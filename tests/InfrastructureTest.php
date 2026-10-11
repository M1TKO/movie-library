<?php
declare(strict_types=1);
namespace App\Tests;

use App\Message\WorkerPing;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Process\Process;

final class InfrastructureTest extends KernelTestCase
{
    private function database(): Connection
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        foreach (['events','run_file_results','run_locations','file_content_links','file_locations','media_files','episodes','seasons','movies','series','runs','messenger_messages','worker_receipts','worker_health'] as $table) { $db->executeStatement('DELETE FROM '.$table); }
        return $db;
    }
    public function testCopyAndEpisodeIdentityAndConstraints(): void
    {
        $db = $this->database();
        $now = gmdate(DATE_ATOM);
        $db->insert('series',['title'=>'Test show','created_at'=>$now,'updated_at'=>$now]);
        $series = (int)$db->lastInsertId();
        $db->insert('seasons',['series_id'=>$series,'season_number'=>0]);
        $season = (int)$db->lastInsertId();
        $episodes=[];
        foreach ([1,2] as $number) { $db->insert('episodes',['season_id'=>$season,'episode_number'=>$number,'title'=>'Part '.$number,'created_at'=>$now,'updated_at'=>$now]); $episodes[]=(int)$db->lastInsertId(); }
        $db->insert('media_files',['original_path'=>'/fixture/show.mkv','normalized_path'=>'/fixture/show.mkv','filename'=>'show.mkv','created_at'=>$now,'updated_at'=>$now]);
        $file=(int)$db->lastInsertId();
        foreach ($episodes as $episode) { $db->insert('file_content_links',['file_id'=>$file,'episode_id'=>$episode]); }
        self::assertSame(2,(int)$db->fetchOne('SELECT COUNT(*) FROM file_content_links'));
        self::assertSame(1,(int)$db->fetchOne('SELECT COUNT(*) FROM media_files'));
        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);
        $this->expectExceptionMessage('CHECK constraint failed');
        $db->insert('file_content_links',['file_id'=>$file]);
    }
    public function testProviderIdentityIsUniqueButUnmatchedTitlesAreAllowed(): void
    {
        $db=$this->database(); $now=gmdate(DATE_ATOM);
        foreach ([null,null,123] as $provider) { $db->insert('movies',['title'=>'Fixture','tmdb_id'=>$provider,'created_at'=>$now,'updated_at'=>$now]); }
        self::assertSame(3,(int)$db->fetchOne('SELECT COUNT(*) FROM movies'));
        $this->expectException(ConstraintViolationException::class);
        $db->insert('movies',['title'=>'Duplicate','tmdb_id'=>123,'created_at'=>$now,'updated_at'=>$now]);
    }
    public function testRemovingLocationKeepsFilesAndAuditSnapshots(): void
    {
        $db=$this->database(); $now=gmdate(DATE_ATOM);
        $db->insert('locations',['label'=>'Fixture','root_path'=>'/fixture','normalized_root'=>'/fixture-'.bin2hex(random_bytes(4)),'created_at'=>$now]); $location=(int)$db->lastInsertId();
        $db->insert('media_files',['original_path'=>'/fixture/movie.mkv','normalized_path'=>'/fixture/movie.mkv','filename'=>'movie.mkv','created_at'=>$now,'updated_at'=>$now]); $file=(int)$db->lastInsertId();
        $db->insert('file_locations',['file_id'=>$file,'location_id'=>$location,'relative_path'=>'movie.mkv']);
        $db->insert('events',['occurred_at'=>$now,'event_type'=>'discovery','file_id'=>$file,'path_snapshot'=>'/fixture/movie.mkv']);
        $db->delete('locations',['id'=>$location]);
        self::assertSame(1,(int)$db->fetchOne('SELECT COUNT(*) FROM media_files'));
        $db->delete('media_files',['id'=>$file]);
        self::assertSame('/fixture/movie.mkv',$db->fetchOne('SELECT path_snapshot FROM events'));
        self::assertNull($db->fetchOne('SELECT file_id FROM events'));
    }
    public function testActiveRunDeduplicationAndPrimaryOutcomes(): void
    {
        $db=$this->database(); $now=gmdate(DATE_ATOM);
        $db->insert('runs',['id'=>'one','job_type'=>'availability','trigger_name'=>'test','queued_at'=>$now,'deduplication_key'=>'all']);
        try { $db->insert('runs',['id'=>'two','job_type'=>'availability','trigger_name'=>'test','queued_at'=>$now,'deduplication_key'=>'all']); self::fail('Duplicate active run was allowed.'); } catch (ConstraintViolationException) {}
        $db->update('runs',['state'=>'completed'],['id'=>'one']);
        $db->insert('runs',['id'=>'two','job_type'=>'availability','trigger_name'=>'test','queued_at'=>$now,'deduplication_key'=>'all']);
        $db->insert('run_file_results',['run_id'=>'one','file_identity'=>'fixture','outcome'=>'unchanged','path_snapshot'=>'/fixture']);
        $this->expectException(ConstraintViolationException::class);
        $db->insert('run_file_results',['run_id'=>'one','file_identity'=>'fixture','outcome'=>'restored','path_snapshot'=>'/fixture']);
    }
    public function testBothDurableWorkersConsumeAndReplayIdempotently(): void
    {
        $db=$this->database();
        $bus=self::getContainer()->get(MessageBusInterface::class);
        foreach (['filesystem','metadata'] as $queue) {
            $id=bin2hex(random_bytes(12));
            foreach ([1,2] as $_) { $bus->dispatch(new WorkerPing($id,$queue),[new TransportNamesStamp([$queue])]); }
            self::assertSame(2,(int)$db->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name=?',[$queue]));
            $process=new Process([PHP_BINARY,'bin/console','messenger:consume',$queue,'--limit=2','--time-limit=10','--no-interaction'],dirname(__DIR__),['APP_ENV'=>'test','APP_DEBUG'=>'0','APP_DATA_DIR'=>$_SERVER['APP_DATA_DIR']]);
            $process->setTimeout(20)->mustRun();
            self::assertSame(1,(int)$db->fetchOne('SELECT COUNT(*) FROM worker_receipts WHERE id=?',[$id]));
            self::assertSame('stopped',$db->fetchOne('SELECT state FROM worker_health WHERE name=?',[$queue]));
            self::assertSame(0,(int)$db->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name=?',[$queue]));
        }
    }
}
