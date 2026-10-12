<?php
declare(strict_types=1);
namespace App\Metadata;
use Doctrine\DBAL\Connection;

final class MatchWriter
{
    public function __construct(private readonly Connection $db) {}
    public static function imdb(?string $value): ?string
    {
        $value=trim($value ?? '');
        if ($value==='') { return null; }
        if (preg_match('/^tt[0-9]{7,10}$/D',$value)) { return $value; }
        if (preg_match('~^https://(?:www\\.)?imdb\\.com/title/(tt[0-9]{7,10})/?$~D',$value,$m)) { return $m[1]; }
        throw new \InvalidArgumentException('Enter a confirmed https://www.imdb.com/title/tt…/ URL. Search URLs are not title links.');
    }
    public function edit(string $table,int $id,string $title,?int $year,?string $imdb,bool $unmatched=false): void
    {
        $this->table($table); $title=trim($title); $imdb=self::imdb($imdb);
        if ($title==='' || mb_strlen($title)>200 || ($year!==null && ($year<1800 || $year>2200))) { throw new \InvalidArgumentException('Enter a title up to 200 characters and a valid optional year.'); }
        $this->db->transactional(function () use ($table,$id,$title,$year,$imdb,$unmatched): void {
            $old=$this->db->fetchAssociative("SELECT * FROM $table WHERE id=?",[$id]); if (!$old) { throw new \InvalidArgumentException('Title no longer exists.'); }
            $values=['title'=>$title,'release_year'=>$year,'imdb_id'=>$imdb,'manual_lock'=>1,'matching_state'=>$unmatched?'unmatched':'manually_confirmed','updated_at'=>$this->stamp()];
            // A local edit of an identified title keeps its provider identity and cached metadata.
            $this->db->update($table,$values,['id'=>$id]);
            $this->event($table,$id,'title_edited',$old,$values,null,'Owner local edit; automatic replacement locked.');
        });
    }
    public function rematch(string $table,int $id): void
    {
        $this->table($table);
        $this->db->transactional(function () use ($table,$id): void {
            $old=$this->db->fetchAssociative("SELECT * FROM $table WHERE id=?",[$id]);
            if (!$old) { throw new \InvalidArgumentException('Title no longer exists.'); }
            $this->db->executeStatement("UPDATE runs SET cancel_requested=1 WHERE deduplication_key=? AND state IN ('queued','running')",['metadata:'.$table.':'.$id]);
            $this->db->executeStatement("UPDATE runs SET state='cancelled',finished_at=?,enrichment_pending=0 WHERE deduplication_key=? AND state='queued'",[gmdate(DATE_ATOM),'metadata:'.$table.':'.$id]);
            $values=['manual_lock'=>0,'matching_state'=>'pending','updated_at'=>$this->stamp()];
            $this->db->update($table,$values,['id'=>$id]);
            $this->event($table,$id,'rematch_requested',$old,$values,null,'Owner explicitly reopened matching.');
        });
    }
    /** Commit provider metadata and merge only established provider identities. */
    public function apply(string $table,int $id,array $data,bool $manual,string $language,string $run,array $episodes=[]): int
    {
        $this->table($table);
        $providerId=(int)($data['id'] ?? 0);
        $name=trim(($data[$table==='movies'?'title':'name'] ?? '') ?: ($data[$table==='movies'?'original_title':'original_name'] ?? ''));
        if ($providerId<1 || $name==='') { throw new ProviderFailure('TMDB returned an incomplete title identity.'); }
        $imdb=null;
        try { $imdb=self::imdb($data['external_ids']['imdb_id'] ?? $data['imdb_id'] ?? null); } catch (\InvalidArgumentException) {}
        $date=$data[$table==='movies'?'release_date':'first_air_date'] ?? '';
        $values=['title'=>$name,'original_title'=>$data[$table==='movies'?'original_title':'original_name'] ?? null,
            'release_year'=>preg_match('/^\\d{4}-/',$date)?(int)substr($date,0,4):null,'synopsis'=>$data['overview'] ?? null,
            'genres'=>json_encode(array_column($data['genres'] ?? [],'name'),JSON_THROW_ON_ERROR),'tmdb_id'=>$providerId,'imdb_id'=>$imdb,
            'poster_path'=>TmdbProvider::posterKey($data['poster_path'] ?? null) ? $data['poster_path'] : null,
            'metadata_language'=>$language,'metadata_source'=>'tmdb','metadata_refreshed_at'=>gmdate(DATE_ATOM),
            'matching_state'=>$manual?'manually_confirmed':'auto_matched','manual_lock'=>(int)$manual,'updated_at'=>$this->stamp()];
        return $this->db->transactional(function () use ($table,$id,$values,$manual,$run,$episodes): int {
            $source=$this->db->fetchAssociative("SELECT * FROM $table WHERE id=?",[$id]);
            if (!$source) { return $id; }
            if (!$manual && $source['manual_lock']) {
                if ($table==='series' && (int)$source['tmdb_id']===$values['tmdb_id']) { $this->updateEpisodes($id,$episodes,$run); }
                return $id;
            }
            $target=$this->db->fetchAssociative("SELECT * FROM $table WHERE tmdb_id=?",[$values['tmdb_id']]);
            $sameIdentity=!$source['tmdb_id'] || (int)$source['tmdb_id']===$values['tmdb_id'];
            if (!$sameIdentity && !$manual) {
                $this->db->update($table,['matching_state'=>'needs_review','updated_at'=>$this->stamp()],['id'=>$id]);
                $this->event($table,$id,'match_conflict',$source,$values,$run,'Changing an established provider identity requires owner confirmation.');
                return $id;
            }
            if (!$sameIdentity && !$target) {
                $this->db->insert($table,$values+['created_at'=>gmdate(DATE_ATOM)]);
                $target=$this->db->fetchAssociative("SELECT * FROM $table WHERE id=?",[(int)$this->db->lastInsertId()]);
            }
            $targetId=$target ? (int)$target['id'] : $id;
            if ($targetId!==$id) {
                if ($table==='movies') {
                    $this->db->executeStatement('INSERT OR IGNORE INTO file_content_links(file_id,movie_id) SELECT file_id,? FROM file_content_links WHERE movie_id=?',[$targetId,$id]);
                    $this->db->delete('file_content_links',['movie_id'=>$id]);
                    if ($sameIdentity && $source['watched']) {
                        $this->db->update('movies',['watched'=>1,'watched_at'=>max($source['watched_at'] ?? '',$target['watched_at'] ?? '') ?: null],['id'=>$targetId]);
                    }
                } else { $this->mergeSeries($id,$targetId,$sameIdentity); }
                // Keep aliases so future filename scans reuse the confirmed identity.
                if ($source['local_identity']) {
                    $this->db->executeStatement('INSERT INTO title_aliases(content_type,local_identity,title_id) VALUES(?,?,?) ON CONFLICT(content_type,local_identity) DO UPDATE SET title_id=excluded.title_id',[$table,$source['local_identity'],$targetId]);
                }
                $this->db->executeStatement('UPDATE title_aliases SET title_id=? WHERE content_type=? AND title_id=?',[$targetId,$table,$id]);
                if ($sameIdentity) {
                    $this->db->executeStatement('INSERT INTO title_redirects(content_type,source_id,target_id) VALUES(?,?,?) ON CONFLICT(content_type,source_id) DO UPDATE SET target_id=excluded.target_id',[$table,$id,$targetId]);
                    $column=$table==='movies'?'movie_id':'series_id';
                    $this->db->executeStatement("UPDATE events SET $column=? WHERE $column=?",[$targetId,$id]);
                    $this->db->executeStatement("DELETE FROM match_candidates WHERE $column=?",[$id]);
                    $this->db->delete($table,['id'=>$id]);
                }
            }
            // A preexisting locked target must retain its owner-approved display metadata.
            if (!$target || !$target['manual_lock'] || $targetId===$id) { $this->db->update($table,$values,['id'=>$targetId]); }
            $this->event($table,$targetId,$sameIdentity ? 'metadata_matched' : 'content_reassigned',$source,$values+['target_id'=>$targetId],$run,
                $sameIdentity ? 'Provider identity confirmed; watched state and history preserved.' : 'Unrelated identity explicitly selected. Previous title and watched state retained separately.');
            if ($table==='series') { $this->updateEpisodes($targetId,$episodes,$run); }
            return $targetId;
        });
    }
    private function mergeSeries(int $source,int $target,bool $same): void
    {
        foreach ($this->db->fetchAllAssociative('SELECT e.*,s.season_number FROM episodes e JOIN seasons s ON s.id=e.season_id WHERE s.series_id=?',[$source]) as $episode) {
            $season=$this->ensureSeason($target,(int)$episode['season_number']);
            $existing=$this->db->fetchAssociative('SELECT * FROM episodes WHERE season_id=? AND episode_number=?',[$season,$episode['episode_number']]);
            if (!$existing) {
                $this->db->insert('episodes',['season_id'=>$season,'episode_number'=>$episode['episode_number'],'title'=>$same?$episode['title']:'Episode '.$episode['episode_number'],
                    'watched'=>$same?$episode['watched']:0,'watched_at'=>$same?$episode['watched_at']:null,'created_at'=>gmdate(DATE_ATOM),'updated_at'=>$this->stamp()]);
                $targetEpisode=(int)$this->db->lastInsertId();
            } else {
                $targetEpisode=(int)$existing['id'];
                if ($same && $episode['watched']) { $this->db->update('episodes',['watched'=>1,'watched_at'=>max($episode['watched_at'] ?? '',$existing['watched_at'] ?? '') ?: null],['id'=>$targetEpisode]); }
            }
            $this->db->executeStatement('INSERT OR IGNORE INTO file_content_links(file_id,episode_id) SELECT file_id,? FROM file_content_links WHERE episode_id=?',[$targetEpisode,$episode['id']]);
            $this->db->delete('file_content_links',['episode_id'=>$episode['id']]);
            if ($same) {
                $this->db->executeStatement('UPDATE events SET episode_id=? WHERE episode_id=?',[$targetEpisode,$episode['id']]);
                $this->db->delete('episodes',['id'=>$episode['id']]);
            }
        }
        if ($same) { $this->db->delete('seasons',['series_id'=>$source]); }
    }
    private function updateEpisodes(int $series,array $details,string $run): void
    {
        foreach ($details as $item) {
            $episode=$this->db->fetchAssociative('SELECT e.* FROM episodes e JOIN seasons s ON s.id=e.season_id WHERE s.series_id=? AND s.season_number=? AND e.episode_number=?',[$series,$item['season'],$item['number']]);
            if (!$episode) { continue; } // Never catalog unowned episodes from the provider guide.
            $seasonData=$item['season_data'] ?? [];
            if ($seasonData) {
                $this->db->update('seasons',['tmdb_id'=>$seasonData['id'] ?? null,'title'=>$seasonData['name'] ?? null],['id'=>$episode['season_id']]);
            }
            $data=$item['data']; $imdb=null;
            try { $imdb=self::imdb($data['external_ids']['imdb_id'] ?? null); } catch (\InvalidArgumentException) {}
            $this->db->update('episodes',['title'=>($data['name'] ?? '') ?: $episode['title'],'air_date'=>$data['air_date'] ?? null,'tmdb_id'=>$data['id'] ?? null,'imdb_id'=>$imdb,'updated_at'=>$this->stamp()],['id'=>$episode['id']]);
            $this->db->insert('events',['occurred_at'=>gmdate(DATE_ATOM),'event_type'=>'episode_metadata_updated','series_id'=>$series,'episode_id'=>$episode['id'],'run_id'=>$run,'title_snapshot'=>$data['name'] ?? $episode['title']]);
        }
    }
    public function assignFile(int $file,string $type,string $title,?int $year,?int $season,array $numbers): array
    {
        $table=$type==='movie'?'movies':($type==='tv'?'series':'');
        $this->table($table); $title=trim($title);
        if ($title==='' || mb_strlen($title)>200 || ($year!==null && ($year<1800 || $year>2200))) { throw new \InvalidArgumentException('Enter a valid title and optional year.'); }
        if ($type==='tv' && ($season===null || $season<0 || $season>999 || !$numbers || count($numbers)>20 || min($numbers)<0 || max($numbers)>9999)) { throw new \InvalidArgumentException('Enter season 0–999 and 1–20 episode numbers (0–9999).'); }
        return $this->db->transactional(function () use ($file,$table,$title,$year,$season,$numbers): array {
            $record=$this->db->fetchAssociative('SELECT * FROM media_files WHERE id=?',[$file]);
            if (!$record) { throw new \InvalidArgumentException('File no longer exists.'); }
            $identity=hash('sha256',mb_strtolower($title,'UTF-8').'|'.($year ?? 'unknown'));
            $id=$this->db->fetchOne('SELECT title_id FROM title_aliases WHERE content_type=? AND local_identity=?',[$table,$identity]) ?: $this->db->fetchOne("SELECT id FROM $table WHERE local_identity=?",[$identity]);
            if (!$id) {
                $this->db->insert($table,['title'=>$title,'release_year'=>$year,'local_identity'=>$identity,'manual_lock'=>1,'matching_state'=>'manually_confirmed','metadata_source'=>'local','created_at'=>gmdate(DATE_ATOM),'updated_at'=>$this->stamp()]);
                $id=(int)$this->db->lastInsertId();
            }
            $before=$this->db->fetchAllAssociative('SELECT movie_id,episode_id FROM file_content_links WHERE file_id=?',[$file]);
            $this->db->delete('file_content_links',['file_id'=>$file]);
            if ($table==='movies') { $this->db->insert('file_content_links',['file_id'=>$file,'movie_id'=>$id]); }
            else {
                $seasonId=$this->ensureSeason((int)$id,$season);
                foreach (array_unique($numbers) as $number) {
                    $this->db->executeStatement('INSERT OR IGNORE INTO episodes(season_id,episode_number,title,created_at,updated_at) VALUES(?,?,?,?,?)',[$seasonId,$number,'Episode '.$number,gmdate(DATE_ATOM),$this->stamp()]);
                    $episode=$this->db->fetchOne('SELECT id FROM episodes WHERE season_id=? AND episode_number=?',[$seasonId,$number]);
                    $this->db->insert('file_content_links',['file_id'=>$file,'episode_id'=>$episode]);
                }
            }
            $this->db->insert('events',['occurred_at'=>gmdate(DATE_ATOM),'event_type'=>'file_content_corrected','file_id'=>$file,
                $table==='movies'?'movie_id':'series_id'=>$id,'title_snapshot'=>$title,'path_snapshot'=>$record['original_path'],
                'before_values'=>json_encode($before,JSON_THROW_ON_ERROR),'after_values'=>json_encode(['type'=>$table,'id'=>$id,'season'=>$season,'episodes'=>$numbers],JSON_THROW_ON_ERROR),
                'reason'=>'Owner corrected content links; watched state remains with each title identity.']);
            return [$table,(int)$id];
        });
    }
    private function ensureSeason(int $series,int $number): int
    {
        $this->db->executeStatement('INSERT OR IGNORE INTO seasons(series_id,season_number) VALUES(?,?)',[$series,$number]);
        return (int)$this->db->fetchOne('SELECT id FROM seasons WHERE series_id=? AND season_number=?',[$series,$number]);
    }
    public function event(string $table,int $id,string $type,array $before,array $after,?string $run,string $reason): void
    {
        $this->db->insert('events',['occurred_at'=>gmdate(DATE_ATOM),'event_type'=>$type,$table==='movies'?'movie_id':'series_id'=>$id,'run_id'=>$run,
            'title_snapshot'=>$before['title'] ?? null,'before_values'=>json_encode($before,JSON_THROW_ON_ERROR),'after_values'=>json_encode($after,JSON_THROW_ON_ERROR),'reason'=>$reason]);
    }
    private function table(string $table): void { if (!in_array($table,['movies','series'],true)) { throw new \InvalidArgumentException('Invalid content type.'); } }
    public function stamp(): string { return (new \DateTimeImmutable())->format('Y-m-d\\TH:i:s.uP'); }
}
