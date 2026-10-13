<?php
declare(strict_types=1);
namespace App\Discovery;

use App\Message\Discover;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class ScanDispatcher
{
    public function __construct(private readonly Connection $db, private readonly MessageBusInterface $bus) {}

    public function dispatch(?int $locationId = null): string
    {
        return $this->db->transactional(function () use ($locationId): string {
            // Take SQLite's write reservation before reading scope: no read-to-write upgrade race.
            $this->db->executeStatement("UPDATE settings SET value=value WHERE name='video_extensions'");
            $locations = $this->db->fetchAllAssociative('SELECT * FROM locations WHERE enabled=1'.($locationId !== null ? ' AND id=?' : '').' ORDER BY id', $locationId !== null ? [$locationId] : []);
            if (!$locations) { throw new \InvalidArgumentException('Select at least one enabled location before scanning.'); }
            if (count($locations) > 50) { throw new \InvalidArgumentException('Scan at most 50 locations at a time.'); }
            $locations = array_map(static fn(array $row) => array_intersect_key($row, array_flip(['id','label','root_path','normalized_root','recursive','content_hint','exclusions'])), $locations);
            $options = ['roots'=>$locations,
                'extensions'=>ScanOptions::extensions((string)$this->db->fetchOne("SELECT value FROM settings WHERE name='video_extensions'")),
                'probe_concurrency'=>max(1,min(2,(int)($this->db->fetchOne("SELECT value FROM settings WHERE name='probe_concurrency'")?:2))),
                'ffprobe'=>(string)$this->db->fetchOne("SELECT value FROM settings WHERE name='ffprobe_executable'")];
            $key = 'discovery:'.hash('sha256', json_encode($options, JSON_THROW_ON_ERROR));
            $active = $this->db->fetchOne("SELECT id FROM runs WHERE deduplication_key=? AND state IN ('queued','running')", [$key]);
            if ($active) { return $active; }
            $id = bin2hex(random_bytes(16));
            $this->db->insert('runs', ['id'=>$id,'job_type'=>'discovery','trigger_name'=>'manual','deduplication_key'=>$key,'options'=>json_encode($options, JSON_THROW_ON_ERROR),'queued_at'=>gmdate(DATE_ATOM)]);
            foreach ($locations as $location) {
                $this->db->insert('run_locations', ['run_id'=>$id,'location_id'=>$location['id'],'root_snapshot'=>$location['root_path'],'accessibility'=>'not_yet_checked','enumeration_outcome'=>'queued']);
            }
            // Doctrine transport uses the same connection: run + message commit or roll back together.
            $this->bus->dispatch(new Discover($id), [new TransportNamesStamp(['filesystem'])]);
            return $id;
        });
    }
}
