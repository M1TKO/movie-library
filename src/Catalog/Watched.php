<?php

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

final class Watched
{
    public function __construct(private readonly Connection $db) {}
    public function targets(string $type, int $id): array
    {
        return match ($type) {
            'movies' => $this->db->fetchAllAssociative('SELECT * FROM movies WHERE id=?', [$id]),
            'episodes' => $this->db->fetchAllAssociative('SELECT * FROM episodes WHERE id=? AND EXISTS(SELECT 1 FROM file_content_links l WHERE l.episode_id=episodes.id)', [$id]),
            'seasons','series' => $this->db->fetchAllAssociative('SELECT e.* FROM episodes e JOIN seasons s ON s.id=e.season_id WHERE ' . ($type === 'seasons' ? 's.id' : 's.series_id') . '=? AND EXISTS(SELECT 1 FROM file_content_links l WHERE l.episode_id=e.id) ORDER BY e.id', [$id]),
            default => throw new \InvalidArgumentException('Invalid watched target.'),
        };
    }
    public function snapshot(array $targets): string
    {
        return hash('sha256', json_encode(array_map(static fn(array $row) => [$row['id'],$row['watched'],$row['watched_at']], $targets), JSON_THROW_ON_ERROR));
    }
    public function change(string $type, int $id, bool $watched, string $snapshot): int
    {
        return $this->db->transactional(function () use ($type, $id, $watched, $snapshot): int {
            $this->db->executeStatement("UPDATE settings SET value=value WHERE name='host_actions_enabled'");
            $targets = $this->targets($type, $id);
            if (!hash_equals($this->snapshot($targets), $snapshot)) {
                throw new \RuntimeException('The catalog or watched state changed. Preview the action again.');
            }
            $now = gmdate(DATE_ATOM);
            $count = 0;
            $table = $type === 'movies' ? 'movies' : 'episodes';
            foreach ($targets as $row) {
                if ((bool) $row['watched'] === $watched) {
                    continue;
                }
                $after = ['watched' => (int) $watched,'watched_at' => $watched ? $now : null];
                $this->db->update($table, $after + ['updated_at' => $now], ['id' => $row['id']]);
                $this->db->insert('events', ['occurred_at' => $now,'event_type' => 'watched_changed',($table === 'movies' ? 'movie_id' : 'episode_id') => $row['id'],'title_snapshot' => $row['title'],'before_values' => json_encode(['watched' => (int) $row['watched'],'watched_at' => $row['watched_at']]),'after_values' => json_encode($after),'reason' => 'Owner watched action']);
                ++$count;
            }
            return $count;
        });
    }
}
