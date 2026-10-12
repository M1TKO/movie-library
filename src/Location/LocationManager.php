<?php
declare(strict_types=1);
namespace App\Location;

use App\Discovery\ScanOptions;
use Doctrine\DBAL\Connection;

final class LocationManager
{
    public function __construct(private readonly Connection $db) {}

    /** Configuration changes serialize with dispatch and cannot change a live traversal's scope. */
    public function change(int $id, array $values, bool $remove = false): void
    {
        $this->db->transactional(function () use ($id, $values, $remove): void {
            $this->db->executeStatement('UPDATE locations SET label=label WHERE id=?', [$id]);
            if (!$this->db->fetchOne('SELECT id FROM locations WHERE id=?', [$id])) { throw new \InvalidArgumentException('Location does not exist.'); }
            foreach ($this->db->fetchFirstColumn("SELECT options FROM runs WHERE job_type IN ('discovery','availability') AND state IN ('queued','running')") as $json) {
                foreach (json_decode($json, true, 512, JSON_THROW_ON_ERROR)['roots'] as $root) {
                    if ((int)$root['id'] === $id) { throw new \InvalidArgumentException('Cancel or finish the queued/running scan for this location before changing it.'); }
                }
            }
            if ($remove) { $this->db->delete('locations', ['id'=>$id]); }
            else {
                $this->db->update('locations', $values, ['id'=>$id]);
                $root = $this->db->fetchAssociative('SELECT * FROM locations WHERE id=?', [$id]);
                $rules = json_decode($root['exclusions'], true, 512, JSON_THROW_ON_ERROR);
                foreach ($this->db->fetchAllAssociative('SELECT * FROM file_locations WHERE location_id=?', [$id]) as $membership) {
                    $inScope = ($root['recursive'] || !str_contains($membership['relative_path'], '/')) && !ScanOptions::excluded($membership['relative_path'], $rules);
                    $this->db->update('file_locations', ['in_scope'=>(int)$inScope], ['file_id'=>$membership['file_id'],'location_id'=>$id]);
                }
            }
            $this->db->executeStatement("UPDATE media_files SET check_outcome='not_monitored',check_error=NULL WHERE NOT EXISTS(SELECT 1 FROM file_locations fl JOIN locations l ON l.id=fl.location_id WHERE fl.file_id=media_files.id AND fl.in_scope=1 AND l.enabled=1)");
            // Enabling is not evidence of availability: retain last-known state until a new scan.
            $this->db->executeStatement("UPDATE media_files SET check_outcome='not_yet_checked' WHERE check_outcome='not_monitored' AND EXISTS(SELECT 1 FROM file_locations fl JOIN locations l ON l.id=fl.location_id WHERE fl.file_id=media_files.id AND fl.in_scope=1 AND l.enabled=1)");
        });
    }
}
