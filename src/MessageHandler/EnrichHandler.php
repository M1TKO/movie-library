<?php
declare(strict_types=1);
namespace App\MessageHandler;
use App\Message\Enrich;
use App\Metadata\{Provider,ProviderFailure,Ranker,MatchWriter,Dispatcher};
use App\Worker\WorkerLifecycle;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Lock\{LockFactory};
use Symfony\Component\Lock\Store\FlockStore;

#[AsMessageHandler]
final class EnrichHandler
{
    public function __construct(private readonly Connection $db,private readonly Provider $provider,private readonly Ranker $ranker,
        private readonly MatchWriter $writer,private readonly WorkerLifecycle $worker,private readonly Dispatcher $dispatcher) {}
    public function __invoke(Enrich $message): void
    {
        $lock=(new LockFactory(new FlockStore($_SERVER['APP_DATA_DIR'])))->createLock('metadata-job');
        if (!$lock->acquire()) { throw new \RuntimeException('Another metadata job is active.'); }
        try {
            $run=$this->db->fetchAssociative('SELECT * FROM runs WHERE id=?',[$message->runId]);
            if (!$run || $run['state']!=='queued') { return; }
            $o=json_decode($run['options'],true,64,JSON_THROW_ON_ERROR);
            if (!in_array($o['table'],['movies','series'],true)) { $this->finish($message->runId,'failed','Invalid metadata job.'); return; }
            $title=$this->db->fetchAssociative('SELECT * FROM '.$o['table'].' WHERE id=?',[$o['id']]);
            if (!$title || $run['cancel_requested'] || !$this->current($o)) { $this->finish($message->runId,'cancelled','Title was edited or the request was cancelled.'); return; }
            $this->db->update('runs',['state'=>'running','started_at'=>gmdate(DATE_ATOM),'heartbeat_at'=>gmdate(DATE_ATOM),'owner_token'=>bin2hex(random_bytes(16))],['id'=>$message->runId]);
            $type=$o['table']==='movies'?'movie':'tv';
            $providerId=$o['provider_id']; $manual=$o['action']==='confirm'; $ranked=[];
            if (!$manual && $title['tmdb_id'] && in_array($o['action'],['auto','refresh'],true)) { $providerId=(int)$title['tmdb_id']; }
            elseif (!$manual) {
                $ranked=$this->ranker->rank($o['query'],$title['release_year']!==null?(int)$title['release_year']:null,$type,$this->provider->search($type,$o['query'],$o['language']));
                if (!$this->current($o)) { $this->finish($message->runId,'cancelled','Title changed while metadata was loading.'); return; }
                $this->db->transactional(function () use ($o,$type,$ranked): void {
                    if (!$this->current($o)) { return; }
                    $column=$o['table']==='movies'?'movie_id':'series_id';
                    $this->db->delete('match_candidates',[$column=>$o['id']]);
                    foreach ($ranked as $candidate) {
                        $this->db->insert('match_candidates',[$column=>$o['id'],'provider'=>'tmdb','provider_id'=>(string)$candidate['id'],'content_type'=>$type,
                            'summary'=>json_encode($candidate,JSON_THROW_ON_ERROR),'score'=>$candidate['score'],'evidence'=>json_encode($candidate['evidence'],JSON_THROW_ON_ERROR)]);
                    }
                });
                foreach (array_slice($ranked,0,5) as $candidate) { $this->provider->cachePoster($candidate['poster_path']); $this->beat($message->runId); }
                if ($o['action']==='search' || !$this->ranker->automatic($ranked)) {
                    $state=$ranked?'needs_review':'unmatched';
                    $this->db->transactional(function () use ($o,$title,$state,$message): void {
                        if ($this->current($o) && !$title['manual_lock']) {
                            $this->db->update($o['table'],['matching_state'=>$state],['id'=>$o['id']]);
                            $this->writer->event($o['table'],$o['id'],'match_review',$title,['matching_state'=>$state],$message->runId,'Search requires owner review; original identity retained.');
                        }
                    });
                    $this->finish($message->runId,'completed',$ranked?'Candidates ready for review.':'No candidates found. Edit the search or leave unmatched.',['enrichment_review'=>1]); return;
                }
                $providerId=$ranked[0]['id'];
            }
            $data=$this->provider->details($type,(int)$providerId,$o['language']);
            if ((int)($data['id'] ?? 0)!==(int)$providerId) { throw new ProviderFailure('TMDB returned a conflicting identity.'); }
            $this->provider->cachePoster($data['poster_path'] ?? null); $this->beat($message->runId);
            $episodes=[]; $episodeErrors=0;
            if ($type==='tv') {
                $owned=$this->db->fetchAllAssociative('SELECT e.episode_number,s.season_number FROM episodes e JOIN seasons s ON s.id=e.season_id WHERE s.series_id=? AND (e.tmdb_id IS NULL OR ?=1) ORDER BY s.season_number,e.episode_number LIMIT 50',[$o['id'],(int)($manual && (int)$title['tmdb_id']!==(int)$providerId)]);
                $seasons=[];
                foreach ($owned as $ep) {
                    if (!$this->current($o) || $this->cancelled($message->runId)) { break; }
                    $season=(int)$ep['season_number']; $number=(int)$ep['episode_number'];
                    try {
                        if (!isset($seasons[$season])) { $seasons[$season]=$this->provider->season((int)$providerId,$season,$o['language']); }
                        $known=array_filter($seasons[$season]['episodes'] ?? [],fn($row)=>(int)$row['episode_number']===$number);
                        if (!$known) { ++$episodeErrors; continue; }
                        $detail=$this->provider->episode((int)$providerId,$season,$number,$o['language']);
                        if ((int)($detail['season_number'] ?? -1)!==$season || (int)($detail['episode_number'] ?? -1)!==$number) { ++$episodeErrors; continue; }
                        $episodes[]=['season'=>$season,'number'=>$number,'data'=>$detail,'season_data'=>$seasons[$season]];
                    } catch (ProviderFailure) { ++$episodeErrors; }
                    $this->beat($message->runId);
                }
            }
            $target=$this->db->transactional(function () use ($o,$data,$manual,$message,$episodes): ?int {
                if (!$this->current($o) || $this->cancelled($message->runId)) { return null; }
                return $this->writer->apply($o['table'],(int)$o['id'],$data,$manual,$o['language'],$message->runId,$episodes);
            });
            if ($target===null) { $this->finish($message->runId,'cancelled','Title changed or job cancelled; metadata was not applied.'); return; }
            if ($episodeErrors) {
                $this->db->update('series',['matching_state'=>'needs_review'],['id'=>$target]);
            }
            $this->finish($message->runId,$episodeErrors?'completed_with_errors':'completed',$episodeErrors ? 'Series matched; some owned episode numbers need review or retry.' : 'Metadata saved.',['enrichment_matched'=>1,'enrichment_failed'=>$episodeErrors,'summary'=>json_encode(['title_id'=>$target,'episode_errors'=>$episodeErrors,'decision'=>$manual?'owner confirmation':($ranked[0]['evidence'] ?? 'existing provider identity')],JSON_THROW_ON_ERROR)]);
            // Continue bounded batches only when every fetched episode succeeded.
            if ($type==='tv' && !$episodeErrors && count($episodes)===50) { $this->dispatcher->dispatch('series',$target,'refresh'); }
        } catch (\Throwable $e) {
            $reason=$e instanceof ProviderFailure || $e instanceof \InvalidArgumentException ? $e->getMessage() : 'Metadata job failed. Local catalog data was retained; retry from Review matches.';
            $this->finish($message->runId,'failed',$reason,['enrichment_failed'=>1]);
        } finally { $lock->release(); }
    }
    private function current(array $o): bool
    {
        $row=$this->db->fetchAssociative('SELECT updated_at,tmdb_id,manual_lock FROM '.$o['table'].' WHERE id=?',[$o['id']]);
        return $row && $row['updated_at']===$o['expected_updated_at'] && $row['tmdb_id']===$o['expected_tmdb_id'] && $row['manual_lock']===$o['expected_lock'];
    }
    private function cancelled(string $run): bool { return (bool)$this->db->fetchOne('SELECT cancel_requested FROM runs WHERE id=?',[$run]); }
    private function beat(string $run): void { $this->db->update('runs',['heartbeat_at'=>gmdate(DATE_ATOM)],['id'=>$run]); $this->worker->heartbeat(); }
    private function finish(string $run,string $state,string $summary,array $extra=[]): void
    {
        $this->db->update('runs',$extra+['state'=>$state,'summary'=>$summary,'finished_at'=>gmdate(DATE_ATOM),'heartbeat_at'=>gmdate(DATE_ATOM),'enrichment_pending'=>0],['id'=>$run]);
    }
}
