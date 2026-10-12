<?php
declare(strict_types=1);
namespace App\MessageHandler;
use App\Message\CheckAvailability;
use App\Worker\WorkerLifecycle;
use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Process\Process;
#[AsMessageHandler]
final class CheckAvailabilityHandler
{
    public function __construct(private readonly Connection $db, private readonly WorkerLifecycle $worker, private readonly float $rootTimeout = 30) {}
    public function __invoke(CheckAvailability $message): void
    {
        $id = $message->runId;
        $lock = (new LockFactory(new FlockStore($_SERVER['APP_DATA_DIR'])))->createLock('filesystem-job');
        if (!$lock->acquire()) { throw new \RuntimeException('Another filesystem job is active.'); }
        try {
            $run = $this->db->fetchAssociative('SELECT * FROM runs WHERE id=?', [$id]);
            if (!$run || $run['state'] !== 'queued') { return; }
            if ($this->cancelled($id)) { $this->db->update('runs', ['state'=>'cancelled','finished_at'=>gmdate(DATE_ATOM)], ['id'=>$id]); $this->db->update('run_locations', ['enumeration_outcome'=>'cancelled'], ['run_id'=>$id]); return; }
            $this->db->update('runs', ['state'=>'running','started_at'=>gmdate(DATE_ATOM),'owner_token'=>bin2hex(random_bytes(16))], ['id'=>$id]);
            $evidence = []; $cancelled = false; $started = microtime(true);
            foreach (json_decode($run['options'], true, 512, JSON_THROW_ON_ERROR)['roots'] as $root) {
                if ($this->cancelled($id)) { $cancelled = true; break; }
                if (microtime(true)-$started > 1800) { break; }
                $current = $this->db->fetchAssociative('SELECT * FROM locations WHERE id=?', [$root['id']]);
                if (!$current || !$current['enabled'] || $current['normalized_root'] !== $root['normalized_root'] || $current['recursive'] !== $root['recursive'] || $current['exclusions'] !== $root['exclusions']) {
                    $this->rootResult($id, $root['id'], 'check_failed', 'skipped'); continue;
                }
                $files = $this->db->fetchAllAssociative('SELECT f.id,fl.relative_path FROM media_files f JOIN file_locations fl ON fl.file_id=f.id WHERE fl.location_id=? AND fl.in_scope=1', [$root['id']]);
                $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/bin/check-known.php'], dirname(__DIR__, 2), null, json_encode(['root'=>$root['root_path'],'files'=>$files], JSON_THROW_ON_ERROR), $this->rootTimeout);
                $buffer = ''; $results = []; $rootState = 'check_failed'; $done = false;
                $consume = function (string $chunk) use (&$buffer, &$results, &$rootState, &$done): void {
                    $buffer .= $chunk;
                    if (strlen($buffer) > 2*1024*1024) { throw new \RuntimeException('Check output limit exceeded.'); }
                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $record = json_decode(substr($buffer, 0, $pos), true, 64, JSON_THROW_ON_ERROR); $buffer = substr($buffer, $pos+1);
                        if ($record['kind'] === 'root') { $rootState = $record['state']; }
                        elseif ($record['kind'] === 'file') { $results[$record['id']] = $record['state']; }
                        elseif ($record['kind'] === 'done') { $done = true; }
                    }
                };
                try {
                    $process->start();
                    while ($process->isRunning()) {
                        $process->checkTimeout(); $consume($process->getIncrementalOutput()); $process->clearOutput(); $process->clearErrorOutput();
                        $this->db->update('runs', ['heartbeat_at'=>gmdate(DATE_ATOM)], ['id'=>$id]); $this->worker->heartbeat();
                        if ($this->cancelled($id)) { $cancelled = true; break; }
                        usleep(50000);
                    }
                    if (!$cancelled) { $consume($process->getIncrementalOutput()); }
                    if (!$process->isSuccessful() || (!$done && $rootState === 'available')) { $rootState = 'check_failed'; }
                } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException) { $rootState = 'check_failed'; }
                finally { if ($process->isRunning()) { $process->stop(0); } }
                foreach ($files as $file) {
                    $evidence[$file['id']][$root['id']] = $results[$file['id']] ?? ($rootState === 'available' ? 'check_failed' : $rootState);
                }
                $this->rootResult($id, $root['id'], $rootState, $cancelled ? 'cancelled' : ($done ? 'completed' : 'completed_with_errors'));
                $this->db->update('locations', ['last_check_at'=>gmdate(DATE_ATOM),'status'=>$rootState === 'available' ? 'accessible' : $rootState,'last_error'=>$rootState === 'available' ? null : 'Root could not be verified.'], ['id'=>$root['id']]);
                // Commit completed known-path evidence after each root; worker interruption retains these outcomes.
                foreach ($this->db->fetchAllAssociative('SELECT * FROM media_files ORDER BY id') as $file) {
                    if ($this->db->fetchOne('SELECT id FROM run_file_results WHERE run_id=? AND file_id=?', [$id,$file['id']])) { continue; }
                    $memberships = $this->db->fetchFirstColumn('SELECT fl.location_id FROM file_locations fl JOIN locations l ON l.id=fl.location_id WHERE fl.file_id=? AND fl.in_scope=1 AND l.enabled=1', [$file['id']]);
                    $states = array_map(fn($rootId) => $evidence[$file['id']][$rootId] ?? null, $memberships);
                    if (in_array('available', $states, true)) { $this->record($id, $file, 'available'); }
                    elseif (!in_array(null, $states, true)) { $this->record($id, $file, $this->resolve($states)); }
                }
                if ($cancelled) { break; }
            }
            foreach ($this->db->fetchAllAssociative('SELECT * FROM media_files ORDER BY id') as $file) {
                if ($this->db->fetchOne('SELECT id FROM run_file_results WHERE run_id=? AND file_id=?', [$id,$file['id']])) { continue; }
                $memberships = $this->db->fetchFirstColumn('SELECT fl.location_id FROM file_locations fl JOIN locations l ON l.id=fl.location_id WHERE fl.file_id=? AND fl.in_scope=1 AND l.enabled=1', [$file['id']]);
                $states = array_map(fn($rootId) => $evidence[$file['id']][$rootId] ?? 'check_failed', $memberships);
                if ($cancelled && !isset($evidence[$file['id']])) { continue; }
                $state = $this->resolve($states);
                $this->record($id, $file, $state);
            }
            $errors = (int)$this->db->fetchOne('SELECT uncheckable FROM runs WHERE id=?', [$id]) + (int)$this->db->fetchOne("SELECT COUNT(*) FROM run_locations WHERE run_id=? AND (error_code IS NOT NULL OR enumeration_outcome='queued')", [$id]);
            $this->db->update('runs', ['state'=>$cancelled ? 'cancelled' : ($errors ? 'completed_with_errors' : 'completed'),'finished_at'=>gmdate(DATE_ATOM),'summary'=>$cancelled ? 'Cancelled; completed checks were retained.' : 'Known paths checked. Uncheckable copies retain last-known availability.'], ['id'=>$id]);
            $this->db->executeStatement("UPDATE run_locations SET enumeration_outcome=? WHERE run_id=? AND enumeration_outcome='queued'", [$cancelled ? 'cancelled' : 'skipped',$id]);
        } catch (\Throwable $e) {
            $this->db->executeStatement("UPDATE runs SET state='failed',finished_at=?,summary='Check failed; committed results retained.' WHERE id=? AND state='running'", [gmdate(DATE_ATOM),$id]);
            throw $e;
        } finally { $lock->release(); }
    }
    private function resolve(array $states): string
    {
        if (!$states) { return 'not_monitored'; }
        if (in_array('available', $states, true)) { return 'available'; }
        if (count(array_filter($states, fn($state) => $state === 'missing')) === count($states)) { return 'missing'; }
        if (in_array('access_denied', $states, true)) { return 'access_denied'; }
        return in_array('check_failed', $states, true) ? 'check_failed' : 'location_offline';
    }
    private function record(string $run, array $file, string $state): void
    {
        $this->db->transactional(function () use ($run, $file, $state): void {
            $now = gmdate(DATE_ATOM); $known = in_array($state, ['available','missing'], true);
            $outcome = !$known ? 'uncheckable' : ($state === $file['last_known_availability'] ? 'unchanged' : ($state === 'missing' ? 'newly_missing' : 'restored'));
            $reason = $known ? null : str_replace('_', ' ', $state).': path could not be verified.';
            $values = ['check_outcome'=>$state,'check_error'=>$reason,'last_checked_at'=>$now,'updated_at'=>$now];
            if ($known) { $values['last_known_availability'] = $state; }
            if ($state === 'available') { $values['last_seen_at'] = $now; }
            $this->db->update('media_files', $values, ['id'=>$file['id']]);
            $this->db->insert('run_file_results', ['run_id'=>$run,'file_id'=>$file['id'],'file_identity'=>$file['normalized_path'],'outcome'=>$outcome,'path_snapshot'=>$file['original_path'],'current_state'=>$state,'diagnostic_code'=>$known ? null : $state,'details'=>$reason]);
            $this->db->executeStatement('UPDATE runs SET examined=examined+1,'.$outcome.'='.$outcome.'+1 WHERE id=?', [$run]);
            if (in_array($outcome, ['newly_missing','restored'], true)) {
                $this->db->insert('events', ['occurred_at'=>$now,'event_type'=>$outcome,'run_id'=>$run,'file_id'=>$file['id'],'path_snapshot'=>$file['original_path'],'before_values'=>json_encode(['availability'=>$file['last_known_availability']]),'after_values'=>json_encode(['availability'=>$state])]);
            }
        });
    }
    private function cancelled(string $id): bool { return (bool)$this->db->fetchOne('SELECT cancel_requested FROM runs WHERE id=?', [$id]); }
    private function rootResult(string $id, int|string $root, string $state, string $outcome): void
    {
        $this->db->update('run_locations', ['accessibility'=>$state === 'available' ? 'accessible' : $state,'enumeration_outcome'=>$outcome,'error_code'=>$state === 'available' ? null : $state,'error_reason'=>$state === 'available' ? null : 'Root could not be verified.'], ['run_id'=>$id,'location_id'=>$root]);
    }
}
