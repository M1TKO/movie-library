<?php
declare(strict_types=1);
namespace App\Availability;
use App\Discovery\ScanOptions;
use Doctrine\DBAL\Connection;

/** Absence requires successful directory listings in every enabled membership. */
final class DiscoveryReconciler
{
    public function __construct(private readonly Connection $db, private readonly AvailabilityRecorder $recorder) {}
    public function reconcile(string $run, array $roots, ?callable $heartbeat = null): void
    {
        $options = json_decode($this->db->fetchOne('SELECT options FROM runs WHERE id=?', [$run]), true, 512, JSON_THROW_ON_ERROR);
        $selected = array_column($options['roots'], 'id');
        foreach ($this->db->fetchAllAssociative('SELECT * FROM media_files f WHERE NOT EXISTS (SELECT 1 FROM run_file_results r WHERE r.run_id=? AND r.file_id=f.id)', [$run]) as $file) {
            if ($heartbeat) { $heartbeat(); }
            if ($this->db->fetchOne('SELECT cancel_requested FROM runs WHERE id=?', [$run])) { return; }
            $memberships = $this->db->fetchAllAssociative('SELECT fl.*,l.* FROM file_locations fl JOIN locations l ON l.id=fl.location_id WHERE fl.file_id=? AND fl.in_scope=1 AND l.enabled=1', [$file['id']]);
            if (!$memberships || !array_intersect($selected, array_column($memberships, 'location_id')) || !in_array(strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION)), $options['extensions'], true)) { continue; }
            $states = [];
            foreach ($memberships as $membership) {
                $evidence = $roots[$membership['location_id']] ?? null;
                $relative = $membership['relative_path'];
                if (ScanOptions::excluded($relative, json_decode($membership['exclusions'], true)) || (!$membership['recursive'] && str_contains($relative, '/'))) { $states[] = 'check_failed'; continue; }
                // A changed scope or an unscanned overlap cannot establish absence.
                if (!$evidence || $evidence['root']['normalized_root'] !== $membership['normalized_root'] || $evidence['root']['recursive'] !== $membership['recursive'] || $evidence['root']['exclusions'] !== $membership['exclusions']) { $states[] = 'check_failed'; continue; }
                if (PHP_OS_FAMILY === 'Windows') { $relative = mb_strtolower(str_replace('\\', '/', $relative)); }
                $directory = ''; $absent = false;
                foreach (explode('/', $relative) as $segment) {
                    if (!isset($evidence['directories'][$directory])) { break; }
                    if (!isset($evidence['directories'][$directory][$segment])) { $absent = true; break; }
                    $directory = $directory === '' ? $segment : $directory.'/'.$segment;
                }
                $states[] = $absent ? 'missing' : 'check_failed';
            }
            $state = count(array_filter($states, static fn($state) => $state === 'missing')) === count($states) ? 'missing' : 'check_failed';
            $this->recorder->record($run, $file, $state);
        }
    }
}
