<?php
declare(strict_types=1);
namespace App\Tests;

use App\Catalog\CatalogWriter;
use App\Discovery\{ScanDispatcher, ScanOptions};
use App\Location\{HostPath, LocationManager};
use App\Message\Discover;
use App\MessageHandler\DiscoverHandler;
use App\Worker\WorkerLifecycle;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

final class DiscoveryTest extends KernelTestCase
{
    private Connection $db;
    private string $fixture;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        foreach (['events','run_file_results','run_locations','file_content_links','file_locations','media_files','episodes','seasons','movies','series','runs','messenger_messages','worker_receipts','worker_health','locations'] as $table) { $this->db->executeStatement('DELETE FROM '.$table); }
        $this->db->update('settings', ['value'=>'missing-fixture-ffprobe-executable'], ['name'=>'ffprobe_executable']);
        $this->db->update('settings', ['value'=>implode(' ', ScanOptions::EXTENSIONS)], ['name'=>'video_extensions']);
        $this->fixture = $_SERVER['APP_DATA_DIR'].'/discovery-'.bin2hex(random_bytes(8));
        mkdir($this->fixture, 0700, true);
    }
    protected function tearDown(): void
    {
        // Only the unique fixture created above; never any selected owner path.
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->fixture, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) { $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($this->fixture);
        parent::tearDown();
    }
    private function location(string $path, bool $recursive = true): int
    {
        $this->db->insert('locations', ['label'=>'Fixture','root_path'=>$path,'normalized_root'=>self::getContainer()->get(HostPath::class)->normalize($path),'recursive'=>(int)$recursive,'exclusions'=>json_encode(ScanOptions::EXCLUSIONS),'created_at'=>gmdate(DATE_ATOM)]);
        return (int)$this->db->lastInsertId();
    }
    private function worker(int $limit = 1): Process
    {
        $process = new Process([PHP_BINARY,'bin/console','messenger:consume','filesystem','--limit='.$limit,'--time-limit=20','--no-interaction'], dirname(__DIR__), ['APP_ENV'=>'test','APP_DEBUG'=>'0','APP_DATA_DIR'=>$_SERVER['APP_DATA_DIR']], null, 30);
        return $process;
    }
    public function testDurableDiscoveryOverlapRepeatUnresolvedAndMediaReadOnly(): void
    {
        mkdir($this->fixture.'/nested'); mkdir($this->fixture.'/sample');
        $data = ['Example.Movie.2020.1080p.mkv','nested/Example.Movie.2020.720p.MP4','nested/Example.Show.S01E01E02.mkv','video001.mkv','ignore.txt','sample/Excluded.mkv','Film.sample.mkv'];
        foreach ($data as $name) { file_put_contents($this->fixture.'/'.$name, 'synthetic media bytes'); }
        $before = [];
        foreach ($data as $name) { $before[$name] = hash_file('sha256', $this->fixture.'/'.$name); }
        $this->location($this->fixture); $this->location($this->fixture.'/nested');
        $dispatcher = self::getContainer()->get(ScanDispatcher::class);
        $run = $dispatcher->dispatch();
        self::assertSame($run, $dispatcher->dispatch());
        self::assertSame(1, (int)$this->db->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
        $this->worker()->mustRun();
        self::assertSame('completed_with_errors', $this->db->fetchOne('SELECT state FROM runs WHERE id=?', [$run]));
        self::assertSame(4, (int)$this->db->fetchOne('SELECT COUNT(*) FROM media_files'));
        self::assertSame(6, (int)$this->db->fetchOne('SELECT COUNT(*) FROM file_locations'));
        self::assertSame(1, (int)$this->db->fetchOne('SELECT COUNT(*) FROM movies'));
        self::assertSame(1, (int)$this->db->fetchOne('SELECT COUNT(*) FROM series'));
        self::assertSame(2, (int)$this->db->fetchOne('SELECT COUNT(*) FROM episodes'));
        self::assertSame(4, (int)$this->db->fetchOne('SELECT added FROM runs WHERE id=?', [$run]));
        self::assertSame(4, (int)$this->db->fetchOne('SELECT COUNT(*) FROM run_file_results WHERE run_id=?', [$run]));
        self::assertSame(1, (int)$this->db->fetchOne('SELECT COUNT(*) FROM media_files f WHERE NOT EXISTS(SELECT 1 FROM file_content_links l WHERE l.file_id=f.id)'));
        self::assertSame(4, (int)$this->db->fetchOne("SELECT COUNT(*) FROM media_files WHERE probe_state='failed' AND width IS NULL"));
        $this->db->executeStatement("UPDATE movies SET watched=1,manual_lock=1,title='My manual title'");
        $again = $dispatcher->dispatch(); $this->worker()->mustRun();
        self::assertSame(4, (int)$this->db->fetchOne('SELECT unchanged FROM runs WHERE id=?', [$again]));
        self::assertSame(4, (int)$this->db->fetchOne('SELECT COUNT(*) FROM events'));
        self::assertSame('My manual title', $this->db->fetchOne('SELECT title FROM movies'));
        self::assertSame(1, (int)$this->db->fetchOne('SELECT watched FROM movies'));
        self::getContainer()->get(DiscoverHandler::class)(new Discover($run));
        self::assertSame(4, (int)$this->db->fetchOne('SELECT COUNT(*) FROM events'));
        foreach ($data as $name) { self::assertSame($before[$name], hash_file('sha256',$this->fixture.'/'.$name)); }
    }
    public function testNoRecursionOfflineRootsAndCancellationNeverInferMissing(): void
    {
        mkdir($this->fixture.'/nested');
        file_put_contents($this->fixture.'/Film.2020.mkv', 'fixture');
        file_put_contents($this->fixture.'/nested/Other.mkv', 'fixture');
        $location = $this->location($this->fixture, false);
        $dispatcher = self::getContainer()->get(ScanDispatcher::class);
        $first = $dispatcher->dispatch($location); $this->worker()->mustRun();
        self::assertSame(1, (int)$this->db->fetchOne('SELECT added FROM runs WHERE id=?', [$first]));
        unlink($this->fixture.'/Film.2020.mkv'); // Synthetic absence only.
        $cancelled = $dispatcher->dispatch($location);
        $this->db->update('runs', ['cancel_requested'=>1], ['id'=>$cancelled]);
        $this->worker()->mustRun();
        self::assertSame('cancelled', $this->db->fetchOne('SELECT state FROM runs WHERE id=?', [$cancelled]));
        $offline = $this->location($this->fixture.'/offline');
        $run = $dispatcher->dispatch($offline); $this->worker()->mustRun();
        self::assertSame('completed_with_errors', $this->db->fetchOne('SELECT state FROM runs WHERE id=?', [$run]));
        self::assertSame('available', $this->db->fetchOne('SELECT last_known_availability FROM media_files'));
        self::assertSame(0, (int)$this->db->fetchOne("SELECT COUNT(*) FROM events WHERE event_type='newly_missing'"));
    }
    public function testIncrementalInspectionAndMembershipChangesRetainState(): void
    {
        file_put_contents($this->fixture.'/Film.2020.mkv', 'fixture');
        $location = $this->location($this->fixture);
        $dispatcher = self::getContainer()->get(ScanDispatcher::class);
        $dispatcher->dispatch(); $this->worker()->mustRun();
        $this->db->executeStatement("UPDATE media_files SET probe_state='complete',probe_error=NULL,width=1920,height=1080,probed_at='preserved'");
        $dispatcher->dispatch(); $this->worker()->mustRun();
        self::assertSame('preserved',$this->db->fetchOne('SELECT probed_at FROM media_files'));
        file_put_contents($this->fixture.'/Film.2020.mkv', 'fixture changed');
        $run = $dispatcher->dispatch(); $this->worker()->mustRun();
        self::assertSame(1,(int)$this->db->fetchOne('SELECT changed FROM runs WHERE id=?',[$run]));
        self::assertNull($this->db->fetchOne('SELECT width FROM media_files'));
        $manager = self::getContainer()->get(LocationManager::class);
        $manager->change($location, ['enabled'=>0]);
        self::assertSame('not_monitored',$this->db->fetchOne('SELECT check_outcome FROM media_files'));
        self::assertSame('available',$this->db->fetchOne('SELECT last_known_availability FROM media_files'));
        $manager->change($location, ['enabled'=>1]);
        self::assertSame('not_yet_checked',$this->db->fetchOne('SELECT check_outcome FROM media_files'));
        $manager->change($location, [], true);
        self::assertSame(1,(int)$this->db->fetchOne('SELECT COUNT(*) FROM media_files'));
        self::assertSame('not_monitored',$this->db->fetchOne('SELECT check_outcome FROM media_files'));
        self::assertSame(2,(int)$this->db->fetchOne('SELECT COUNT(*) FROM events'));
    }
    public function testWorkerRestartRecoversInterruptedRunWithoutReplayingIt(): void
    {
        $this->location($this->fixture);
        $run = self::getContainer()->get(ScanDispatcher::class)->dispatch();
        $this->db->update('runs', ['state'=>'running','owner_token'=>'dead-worker'], ['id'=>$run]);
        $this->db->update('run_locations', ['enumeration_outcome'=>'running'], ['run_id'=>$run]);
        $this->worker()->mustRun();
        self::assertSame('interrupted',$this->db->fetchOne('SELECT state FROM runs WHERE id=?',[$run]));
        self::assertSame('interrupted',$this->db->fetchOne('SELECT enumeration_outcome FROM run_locations WHERE run_id=?',[$run]));
        self::assertSame(0,(int)$this->db->fetchOne('SELECT COUNT(*) FROM media_files'));
    }
    public function testQueuedScopesCannotBeChanged(): void
    {
        $location = $this->location($this->fixture);
        self::getContainer()->get(ScanDispatcher::class)->dispatch();
        $this->expectException(\InvalidArgumentException::class);
        self::getContainer()->get(LocationManager::class)->change($location,['enabled'=>0]);
    }

    private function slowProbe(): string
    {
        $php = $this->fixture.'/slow-probe.php';
        file_put_contents($php, '<?php file_put_contents(__DIR__."/probe-started", "yes"); usleep(2000000); echo json_encode(["streams"=>[["codec_type"=>"video","width"=>160,"height"=>90]]]);');
        $executable = $this->fixture.(PHP_OS_FAMILY === 'Windows' ? '/slow-probe.cmd' : '/slow-probe');
        $command = PHP_OS_FAMILY === 'Windows' ? '@echo off'."\r\n".'"'.PHP_BINARY.'" "'.$php.'"'."\r\n" : '#!/bin/sh'."\n".'exec '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($php)."\n";
        file_put_contents($executable,$command); chmod($executable,0700);
        $this->db->update('settings',['value'=>$executable],['name'=>'ffprobe_executable']);
        return $executable;
    }

    public function testActiveCancellationKeepsCommittedDiscoveriesAndHeartbeat(): void
    {
        $this->slowProbe();
        file_put_contents($this->fixture.'/First.2020.mkv','first');
        file_put_contents($this->fixture.'/Second.2020.mkv','second');
        $this->location($this->fixture);
        $dispatcher = self::getContainer()->get(ScanDispatcher::class);
        $run = $dispatcher->dispatch();
        $process = $this->worker(); $process->start();
        try {
            $deadline = microtime(true) + 15;
            while (microtime(true) < $deadline && (int)$this->db->fetchOne('SELECT examined FROM runs WHERE id=?',[$run]) === 0 && $process->isRunning()) { usleep(50000); }
            self::assertGreaterThan(0,(int)$this->db->fetchOne('SELECT examined FROM runs WHERE id=?',[$run]));
            self::assertNotEmpty($this->db->fetchOne('SELECT heartbeat_at FROM runs WHERE id=?',[$run]));
            // Updating diagnostic location fields cannot defeat coalescing.
            $this->db->executeStatement("UPDATE locations SET status='accessible',last_scan_at=?",[gmdate(DATE_ATOM)]);
            self::assertSame($run,$dispatcher->dispatch());
            $this->db->update('runs',['cancel_requested'=>1],['id'=>$run]);
            $process->wait();
            self::assertSame('cancelled',$this->db->fetchOne('SELECT state FROM runs WHERE id=?',[$run]));
            self::assertGreaterThan(0,(int)$this->db->fetchOne('SELECT COUNT(*) FROM media_files'));
            self::assertSame(0,(int)$this->db->fetchOne("SELECT COUNT(*) FROM media_files WHERE last_known_availability='missing'"));
        } finally { if ($process->isRunning()) { $process->stop(0); } }
    }

    public function testBoundedRootTimeoutRetainsUnseenState(): void
    {
        $this->slowProbe(); file_put_contents($this->fixture.'/Film.2020.mkv','fixture');
        $this->location($this->fixture);
        $run = self::getContainer()->get(ScanDispatcher::class)->dispatch();
        $handler = new DiscoverHandler($this->db,self::getContainer()->get(CatalogWriter::class),self::getContainer()->get(WorkerLifecycle::class),0.3,2);
        $started = microtime(true); $handler(new Discover($run));
        self::assertLessThan(5,microtime(true)-$started);
        self::assertSame('completed_with_errors',$this->db->fetchOne('SELECT state FROM runs WHERE id=?',[$run]));
        self::assertSame('timeout',$this->db->fetchOne('SELECT error_code FROM run_locations WHERE run_id=?',[$run]));
        self::assertSame(0,(int)$this->db->fetchOne('SELECT newly_missing FROM runs WHERE id=?',[$run]));
    }

    public function testChangingFileRetainsRetryStateWithoutTechnicalMetadata(): void
    {
        $this->slowProbe(); file_put_contents($this->fixture.'/Film.2020.mkv','fixture');
        $this->location($this->fixture);
        self::getContainer()->get(ScanDispatcher::class)->dispatch();
        $process = $this->worker(); $process->start();
        try {
            $deadline = microtime(true) + 10;
            while (!is_file($this->fixture.'/probe-started') && microtime(true) < $deadline && $process->isRunning()) { clearstatcache(); usleep(50000); }
            self::assertFileExists($this->fixture.'/probe-started');
            file_put_contents($this->fixture.'/Film.2020.mkv','fixture growing while inspection runs');
            $process->wait();
            self::assertSame('retry',$this->db->fetchOne('SELECT probe_state FROM media_files'));
            self::assertNull($this->db->fetchOne('SELECT width FROM media_files'));
            self::assertSame(1,(int)$this->db->fetchOne('SELECT COUNT(*) FROM media_files'));
        } finally { if ($process->isRunning()) { $process->stop(0); } }
    }

    public function testDisabledOverlappingRootDoesNotStopOtherMembership(): void
    {
        mkdir($this->fixture.'/nested'); file_put_contents($this->fixture.'/nested/Film.2020.mkv','fixture');
        $parent = $this->location($this->fixture); $nested = $this->location($this->fixture.'/nested');
        self::getContainer()->get(ScanDispatcher::class)->dispatch(); $this->worker()->mustRun();
        $manager = self::getContainer()->get(LocationManager::class);
        $manager->change($parent,['enabled'=>0]);
        self::assertSame('available',$this->db->fetchOne('SELECT check_outcome FROM media_files'));
        $manager->change($nested,['exclusions'=>'["*.mkv"]']);
        self::assertSame('not_monitored',$this->db->fetchOne('SELECT check_outcome FROM media_files'));
        self::assertSame('available',$this->db->fetchOne('SELECT last_known_availability FROM media_files'));
    }

    public function testSymbolicLinksAndWindowsJunctionsAreNotTraversed(): void
    {
        mkdir($this->fixture.'/media'); mkdir($this->fixture.'/target');
        file_put_contents($this->fixture.'/target/Outside.2020.mkv','fixture');
        $link = $this->fixture.'/media/link';
        if (PHP_OS_FAMILY === 'Windows') {
            (new Process(['cmd','/c','mklink','/J',str_replace('/','\\',$link),str_replace('/','\\',$this->fixture.'/target')],null,null,null,5))->mustRun();
        } else { self::assertTrue(symlink($this->fixture.'/target',$link)); }
        try {
            $this->location($this->fixture.'/media');
            $run = self::getContainer()->get(ScanDispatcher::class)->dispatch(); $this->worker()->mustRun();
            self::assertSame(0,(int)$this->db->fetchOne('SELECT COUNT(*) FROM media_files'));
            self::assertSame('completed',$this->db->fetchOne('SELECT state FROM runs WHERE id=?',[$run]));
            self::assertFileExists($this->fixture.'/target/Outside.2020.mkv');
        } finally { PHP_OS_FAMILY === 'Windows' ? rmdir($link) : unlink($link); }
    }
    private function check(): string
    {
        $id = self::getContainer()->get(\App\Availability\CheckDispatcher::class)->dispatch();
        $this->worker()->mustRun();
        return $id;
    }
    public function testAvailabilityMissingRestoredAndNoDiscoveryOrReprobe(): void
    {
        $path = $this->fixture.'/Film.2020.mkv'; file_put_contents($path, 'fixture');
        $this->location($this->fixture);
        self::getContainer()->get(ScanDispatcher::class)->dispatch(); $this->worker()->mustRun();
        $this->db->executeStatement("UPDATE media_files SET probe_state='complete',probed_at='preserved',width=1920");
        $lastSeen = $this->db->fetchOne('SELECT last_seen_at FROM media_files');
        unlink($path);
        file_put_contents($this->fixture.'/Not.Discovered.2025.mkv', 'new');
        $run = $this->check();
        self::assertSame('missing',$this->db->fetchOne('SELECT last_known_availability FROM media_files'));
        self::assertSame($lastSeen,$this->db->fetchOne('SELECT last_seen_at FROM media_files'));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT newly_missing FROM runs WHERE id=?',[$run]));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT COUNT(*) FROM media_files'));
        self::assertSame('preserved',$this->db->fetchOne('SELECT probed_at FROM media_files'));
        $this->check();
        self::assertSame(1,(int)$this->db->fetchOne("SELECT COUNT(*) FROM events WHERE event_type='newly_missing'"));
        file_put_contents($path,'restored fixture');
        $run = $this->check();
        self::assertSame(1,(int)$this->db->fetchOne('SELECT restored FROM runs WHERE id=?',[$run]));
        self::assertSame('available',$this->db->fetchOne('SELECT check_outcome FROM media_files'));
        self::assertSame(1920,(int)$this->db->fetchOne('SELECT width FROM media_files'));
    }
    public function testAvailabilityOfflineOverlapDisabledAndDebounce(): void
    {
        mkdir($this->fixture.'/nested'); file_put_contents($this->fixture.'/nested/Film.mkv','fixture');
        $parent = $this->location($this->fixture); $nested = $this->location($this->fixture.'/nested');
        self::getContainer()->get(ScanDispatcher::class)->dispatch(); $this->worker(1)->mustRun();
        $checks = self::getContainer()->get(\App\Availability\CheckDispatcher::class);
        $id = $checks->dispatch('library_session');
        self::assertSame($id,$checks->dispatch('backend_start'));
        $this->worker()->mustRun();
        self::assertSame($id,$checks->dispatch('library_session'));
        self::getContainer()->get(LocationManager::class)->change($parent,['enabled'=>0]);
        rename($this->fixture.'/nested',$this->fixture.'/disconnected');
        $run = $this->check();
        self::assertSame('location_offline',$this->db->fetchOne('SELECT check_outcome FROM media_files'));
        self::assertSame('available',$this->db->fetchOne('SELECT last_known_availability FROM media_files'));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT uncheckable FROM runs WHERE id=?',[$run]));
        self::getContainer()->get(LocationManager::class)->change($nested,['enabled'=>0]);
        $this->check();
        self::assertSame('not_monitored',$this->db->fetchOne('SELECT check_outcome FROM media_files'));
        self::assertSame(0,(int)$this->db->fetchOne("SELECT COUNT(*) FROM events WHERE event_type='newly_missing'"));
    }
    public function testAvailabilityCancelledAndInterruptedRunsPreserveState(): void
    {
        file_put_contents($this->fixture.'/Film.mkv','fixture'); $this->location($this->fixture);
        self::getContainer()->get(ScanDispatcher::class)->dispatch(); $this->worker()->mustRun();
        unlink($this->fixture.'/Film.mkv');
        $checks = self::getContainer()->get(\App\Availability\CheckDispatcher::class);
        $id = $checks->dispatch(); $this->db->update('runs',['cancel_requested'=>1],['id'=>$id]); $this->worker()->mustRun();
        self::assertSame('cancelled',$this->db->fetchOne('SELECT state FROM runs WHERE id=?',[$id]));
        self::assertSame('available',$this->db->fetchOne('SELECT last_known_availability FROM media_files'));
        $id = $checks->dispatch(); $this->db->update('runs',['state'=>'running'],['id'=>$id]); $this->worker()->mustRun();
        self::assertSame('interrupted',$this->db->fetchOne('SELECT state FROM runs WHERE id=?',[$id]));
        self::assertSame('available',$this->db->fetchOne('SELECT last_known_availability FROM media_files'));
    }
    public function testRelocationRetainsIdentityHistoryAndWatchedState(): void
    {
        mkdir($this->fixture.'/old'); mkdir($this->fixture.'/new');
        file_put_contents($this->fixture.'/old/Film.2020.mkv','fixture');
        $location = $this->location($this->fixture.'/old');
        self::getContainer()->get(ScanDispatcher::class)->dispatch(); $this->worker()->mustRun();
        $fileId = $this->db->fetchOne('SELECT id FROM media_files');
        $this->db->executeStatement('UPDATE movies SET watched=1,manual_lock=1');
        rename($this->fixture.'/old/Film.2020.mkv',$this->fixture.'/new/Film.2020.mkv');
        self::getContainer()->get(\App\Location\LocationRelocator::class)->relocate($location,$this->fixture.'/new');
        self::assertSame($fileId,$this->db->fetchOne('SELECT id FROM media_files'));
        self::assertSame('not_yet_checked',$this->db->fetchOne('SELECT check_outcome FROM media_files'));
        $this->check();
        self::assertSame('available',$this->db->fetchOne('SELECT check_outcome FROM media_files'));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT watched FROM movies'));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT manual_lock FROM movies'));
        self::assertSame(1,(int)$this->db->fetchOne("SELECT COUNT(*) FROM events WHERE event_type='path_changed'"));
    }
    public function testEquivalentDiscoveryCoalescesAvailabilityAndRelocationConflictsAreAtomic(): void
    {
        mkdir($this->fixture.'/old'); mkdir($this->fixture.'/new');
        file_put_contents($this->fixture.'/old/Film.2020.mkv','fixture');
        file_put_contents($this->fixture.'/new/Film.2020.mkv','second fixture');
        $old = $this->location($this->fixture.'/old');
        $scan = self::getContainer()->get(ScanDispatcher::class)->dispatch();
        self::assertNull(self::getContainer()->get(\App\Availability\CheckDispatcher::class)->dispatch());
        $this->worker()->mustRun();
        $scan = self::getContainer()->get(ScanDispatcher::class)->dispatch();
        self::assertSame($scan,self::getContainer()->get(\App\Availability\CheckDispatcher::class)->dispatch());
        $this->worker()->mustRun();
        $new = $this->location($this->fixture.'/new');
        self::getContainer()->get(ScanDispatcher::class)->dispatch($new); $this->worker()->mustRun();
        $before = $this->db->fetchAllAssociative('SELECT id,original_path FROM media_files ORDER BY id');
        try {
            self::getContainer()->get(\App\Location\LocationRelocator::class)->relocate($old,$this->fixture.'/new');
            self::fail('Relocation must reject configured/colliding destination.');
        } catch (\InvalidArgumentException) {}
        self::assertSame($before,$this->db->fetchAllAssociative('SELECT id,original_path FROM media_files ORDER BY id'));
    }
    public function testAvailabilityTimeoutNeverInfersMissing(): void
    {
        file_put_contents($this->fixture.'/Film.2020.mkv','fixture'); $this->location($this->fixture);
        self::getContainer()->get(ScanDispatcher::class)->dispatch(); $this->worker()->mustRun();
        unlink($this->fixture.'/Film.2020.mkv');
        $id = self::getContainer()->get(\App\Availability\CheckDispatcher::class)->dispatch();
        $handler = new \App\MessageHandler\CheckAvailabilityHandler($this->db,self::getContainer()->get(WorkerLifecycle::class),0.001);
        $handler(new \App\Message\CheckAvailability($id));
        self::assertSame('available',$this->db->fetchOne('SELECT last_known_availability FROM media_files'));
        self::assertSame('check_failed',$this->db->fetchOne('SELECT check_outcome FROM media_files'));
        self::assertSame(0,(int)$this->db->fetchOne('SELECT newly_missing FROM runs WHERE id=?',[$id]));
        self::assertSame('completed_with_errors',$this->db->fetchOne('SELECT state FROM runs WHERE id=?',[$id]));
    }
}