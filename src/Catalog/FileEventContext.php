<?php

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

final class FileEventContext
{
    public static function snapshot(Connection $db, int $file): array
    {
        $links = $db->fetchAllAssociative('SELECT m.id movie_id,m.title movie_title,e.id episode_id,s.id series_id,s.title series_title FROM file_content_links l LEFT JOIN movies m ON m.id=l.movie_id LEFT JOIN episodes e ON e.id=l.episode_id LEFT JOIN seasons se ON se.id=e.season_id LEFT JOIN series s ON s.id=se.series_id WHERE l.file_id=? ORDER BY l.id', [$file]);
        if (!$links) {
            return [];
        }
        $first = $links[0];
        return ['movie_id' => $first['movie_id'],'episode_id' => $first['episode_id'],'series_id' => $first['series_id'],'title_snapshot' => implode(' / ', array_unique(array_map(static fn($link) => $link['movie_title'] ?? $link['series_title'] ?? '', $links)))];
    }
}
