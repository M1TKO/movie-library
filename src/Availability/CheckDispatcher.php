<?php

declare(strict_types=1);

namespace App\Availability;

use App\Message\CheckAvailability;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class CheckDispatcher
{
    public function __construct(private readonly Connection $db, private readonly MessageBusInterface $bus) {}
    public function dispatch(string $trigger = 'manual'): ?string
    {
        return $this->db->transactional(function () use ($trigger): ?string {
            $this->db->executeStatement("UPDATE settings SET value=value WHERE name='video_extensions'");
            $active = $this->db->fetchOne("SELECT id FROM runs WHERE job_type='availability' AND state IN ('queued','running') ORDER BY queued_at LIMIT 1");
            if ($active) {
                return $active;
            }
            if ($trigger === 'library_session') {
                $recent = $this->db->fetchOne("SELECT id FROM runs WHERE job_type='availability' AND queued_at>=? ORDER BY queued_at DESC LIMIT 1", [gmdate(DATE_ATOM, time() - 60)]);
                if ($recent) {
                    return $recent;
                }
            }
            if (!(int) $this->db->fetchOne('SELECT COUNT(*) FROM media_files')) {
                return null;
            }
            $roots = $this->db->fetchAllAssociative('SELECT * FROM locations WHERE enabled=1 ORDER BY id');
            $scope = static fn(array $items) => array_map(static fn(array $item) => array_intersect_key($item, array_flip(['id','normalized_root','recursive','exclusions'])), $items);
            foreach ($this->db->fetchAllAssociative("SELECT id,options FROM runs WHERE job_type='discovery' AND state IN ('queued','running') ORDER BY queued_at") as $scan) {
                if ($scope(json_decode($scan['options'], true, 512, JSON_THROW_ON_ERROR)['roots']) === $scope($roots)) {
                    return $scan['id'];
                }
            }
            $id = bin2hex(random_bytes(16));
            $this->db->insert('runs', ['id' => $id,'job_type' => 'availability','trigger_name' => $trigger,'deduplication_key' => 'availability','options' => json_encode(['roots' => $roots], JSON_THROW_ON_ERROR),'queued_at' => gmdate(DATE_ATOM)]);
            foreach ($roots as $root) {
                $this->db->insert('run_locations', ['run_id' => $id,'location_id' => $root['id'],'root_snapshot' => $root['root_path'],'accessibility' => 'not_yet_checked','enumeration_outcome' => 'queued']);
            }
            $this->bus->dispatch(new CheckAvailability($id), [new TransportNamesStamp(['filesystem'])]);
            return $id;
        });
    }
}
