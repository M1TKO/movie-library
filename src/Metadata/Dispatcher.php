<?php

declare(strict_types=1);

namespace App\Metadata;

use App\Message\Enrich;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;

final class Dispatcher
{
    public function __construct(private readonly Connection $db, private readonly MessageBusInterface $bus, private readonly Credentials $credentials) {}
    public function dispatch(string $table, int $id, string $action = 'auto', ?string $query = null, ?int $providerId = null): ?string
    {
        if (!in_array($table, ['movies','series'], true) || !in_array($action, ['auto','search','confirm','refresh','rematch'], true)) {
            throw new \InvalidArgumentException('Invalid metadata request.');
        }
        return $this->db->transactional(function () use ($table, $id, $action, $query, $providerId): ?string {
            // Acquire SQLite's write lock before reading the active-job identity.
            $this->db->executeStatement("UPDATE settings SET value=value WHERE name='metadata_language'");
            $title = $this->db->fetchAssociative('SELECT * FROM ' . $table . ' WHERE id=?', [$id]);
            if (!$title) {
                throw new \InvalidArgumentException('Title no longer exists.');
            }
            if ($action === 'auto' && (($title['manual_lock'] && !($table === 'series' && $title['tmdb_id'])) || ($title['tmdb_id'] && $table === 'movies') || (!$title['tmdb_id'] && $title['matching_state'] !== 'pending') || !$this->credentials->configured())) {
                return null;
            }
            if ($action === 'auto' && $table === 'series' && $title['tmdb_id'] && !$this->db->fetchOne('SELECT COUNT(*) FROM episodes e JOIN seasons s ON s.id=e.season_id WHERE s.series_id=? AND e.tmdb_id IS NULL', [$id])) {
                return null;
            }
            $query = trim($query ?? $title['title']);
            SearchQuery::validate($query);
            $key = 'metadata:' . $table . ':' . $id;
            $active = $this->db->fetchAssociative("SELECT id,options FROM runs WHERE deduplication_key=? AND state IN ('queued','running')", [$key]);
            if ($active) {
                $options = json_decode($active['options'], true, 64, JSON_THROW_ON_ERROR);
                if ($action === 'auto' || ($options['action'] === $action && $options['query'] === $query && $options['provider_id'] === $providerId)) {
                    return $active['id'];
                }
                throw new \InvalidArgumentException('A metadata job is already queued or running for this title. Wait for it to finish before selecting a different action.');
            }
            if ($action === 'confirm' && (!$providerId || !$this->db->fetchOne('SELECT id FROM match_candidates WHERE ' . ($table === 'movies' ? 'movie_id' : 'series_id') . '=? AND provider_id=? AND provider=?', [$id,(string) $providerId,'tmdb']))) {
                throw new \InvalidArgumentException('Search and select a current candidate first.');
            }
            $run = bin2hex(random_bytes(16));
            $options = ['table' => $table,'id' => $id,'action' => $action,'query' => $query,'provider_id' => $providerId,
                'language' => $this->db->fetchOne("SELECT value FROM settings WHERE name='metadata_language'") ?: 'en-US',
                'expected_updated_at' => $title['updated_at'],'expected_tmdb_id' => $title['tmdb_id'],'expected_lock' => $title['manual_lock']];
            $this->db->insert('runs', ['id' => $run,'job_type' => 'enrichment','trigger_name' => $action,'deduplication_key' => $key,'options' => json_encode($options, JSON_THROW_ON_ERROR),'queued_at' => gmdate(DATE_ATOM),'enrichment_pending' => 1]);
            $this->bus->dispatch(new Enrich($run));
            return $run;
        });
    }
    public function backlog(): int
    {
        $count = 0;
        foreach (['movies','series'] as $table) {
            foreach ($this->db->fetchFirstColumn("SELECT id FROM $table WHERE manual_lock=0 AND matching_state='pending' ORDER BY id") as $id) {
                if ($this->dispatch($table, (int) $id)) {
                    ++$count;
                }
            }
        }
        return $count;
    }
}
