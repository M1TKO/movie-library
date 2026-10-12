<?php
declare(strict_types=1);
namespace App\Location;
use Doctrine\DBAL\Connection;
final class LocationRelocator
{
    public function __construct(private readonly Connection $db, private readonly HostPath $paths) {}
    public function relocate(int $id, string $path): void
    {
        $normalized = $this->paths->normalize($path);
        $this->db->transactional(function () use ($id, $path, $normalized): void {
            $this->db->executeStatement('UPDATE locations SET label=label WHERE id=?', [$id]);
            $root = $this->db->fetchAssociative('SELECT * FROM locations WHERE id=?', [$id]);
            if (!$root) { throw new \InvalidArgumentException('Location does not exist.'); }
            foreach ($this->db->fetchFirstColumn("SELECT options FROM runs WHERE job_type IN ('discovery','availability') AND state IN ('queued','running')") as $json) {
                foreach (json_decode($json, true, 512, JSON_THROW_ON_ERROR)['roots'] as $scope) {
                    if ((int)$scope['id'] === $id) { throw new \InvalidArgumentException('Finish or cancel active jobs before relocating this location.'); }
                }
            }
            if ($normalized === $root['normalized_root']) { return; }
            if ($this->db->fetchOne('SELECT id FROM locations WHERE normalized_root=? AND id<>?', [$normalized,$id])) { throw new \InvalidArgumentException('This folder is already configured.'); }
            $files = $this->db->fetchAllAssociative('SELECT f.*,fl.relative_path FROM media_files f JOIN file_locations fl ON fl.file_id=f.id WHERE fl.location_id=?', [$id]);
            $changes = [];
            foreach ($files as $file) {
                if ((int)$this->db->fetchOne('SELECT COUNT(*) FROM file_locations WHERE file_id=?', [$file['id']]) > 1) { throw new \InvalidArgumentException('This location shares catalog files with another root. Remove the overlapping location configuration before relocating.'); }
                $target = rtrim($path, '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file['relative_path']);
                $key = $this->paths->normalize($target);
                if ($this->db->fetchOne('SELECT id FROM media_files WHERE normalized_path=? AND id<>?', [$key,$file['id']])) { throw new \InvalidArgumentException('A proposed path already belongs to another catalog copy. No paths were changed.'); }
                $changes[] = [$file,$target,$key];
            }
            $now = gmdate(DATE_ATOM);
            foreach ($changes as [$file,$target,$key]) {
                $this->db->update('media_files', ['original_path'=>$target,'normalized_path'=>$key,'check_outcome'=>'not_yet_checked','check_error'=>null,'updated_at'=>$now], ['id'=>$file['id']]);
                $this->db->insert('events', ['occurred_at'=>$now,'event_type'=>'path_changed','file_id'=>$file['id'],'path_snapshot'=>$target,'before_values'=>json_encode(['path'=>$file['original_path']]),'after_values'=>json_encode(['path'=>$target]),'reason'=>'Owner explicitly confirmed location relocation.']);
            }
            $this->db->update('locations', ['root_path'=>$path,'normalized_root'=>$normalized,'status'=>'not_yet_checked','last_error'=>null], ['id'=>$id]);
        });
    }
}
