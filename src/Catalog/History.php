<?php
declare(strict_types=1);
namespace App\Catalog;
use Doctrine\DBAL\Connection;
final class History
{
    public function __construct(private readonly Connection $db) {}
    public function page(string $type, int $id, int $page): array
    {
        $where = match ($type) {
            'file' => 'ev.file_id=:id',
            'movies' => 'ev.movie_id=:id OR EXISTS (SELECT 1 FROM file_content_links l WHERE l.file_id=ev.file_id AND l.movie_id=:id)',
            'series' => 'ev.series_id=:id OR EXISTS (SELECT 1 FROM episodes e JOIN seasons s ON s.id=e.season_id WHERE s.series_id=:id AND (e.id=ev.episode_id OR EXISTS (SELECT 1 FROM file_content_links l WHERE l.episode_id=e.id AND l.file_id=ev.file_id)))',
            default => throw new \InvalidArgumentException('Invalid history target.'),
        };
        $total = (int)$this->db->fetchOne('SELECT COUNT(*) FROM events ev WHERE '.$where, ['id'=>$id]);
        $page = max(1, min($page, max(1, (int)ceil($total / 50))));
        return ['events'=>$this->db->fetchAllAssociative('SELECT ev.* FROM events ev WHERE '.$where.' ORDER BY ev.id DESC LIMIT 50 OFFSET '.(($page-1)*50), ['id'=>$id]), 'eventPage'=>$page,'eventTotal'=>$total];
    }
}
