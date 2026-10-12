<?php
declare(strict_types=1);
namespace App\Catalog;
use Doctrine\DBAL\Connection;
final class Experience
{
    public function __construct(private readonly Connection $db) {}
    public function totals(string $files='SELECT * FROM media_files',array $params=[]): array
    {
        return $this->db->fetchAssociative("SELECT COUNT(*) files,COALESCE(SUM(size_bytes),0) bytes,COALESCE(SUM(CASE WHEN check_outcome='available' THEN size_bytes ELSE 0 END),0) available_bytes,COALESCE(SUM(check_outcome='available'),0) available,COALESCE(SUM(check_outcome='missing'),0) missing,COALESCE(SUM(check_outcome NOT IN ('available','missing')),0) uncheckable FROM (".$files.') f',$params);
    }
    public function watchedTotals(string $movies='SELECT id,watched FROM movies',string $episodes='SELECT e.id,e.watched FROM episodes e WHERE EXISTS(SELECT 1 FROM file_content_links l WHERE l.episode_id=e.id)',array $params=[]): array
    {
        return $this->db->fetchAssociative('SELECT COUNT(*) titles,COALESCE(SUM(watched),0) watched,COALESCE(SUM(watched=0),0) unwatched FROM ('.$movies.' UNION ALL '.$episodes.') content',$params);
    }
    public function seriesStates(string $selected='SELECT id FROM series',array $params=[]): array
    {
        return $this->db->fetchAssociative('WITH selected AS ('.$selected.'), counts AS (SELECT t.id,COUNT(e.id) total,COALESCE(SUM(e.watched),0) watched FROM selected t LEFT JOIN seasons s ON s.series_id=t.id LEFT JOIN episodes e ON e.season_id=s.id AND EXISTS(SELECT 1 FROM file_content_links l WHERE l.episode_id=e.id) GROUP BY t.id) SELECT COALESCE(SUM(watched>0 AND watched=total),0) watched,COALESCE(SUM(watched=0),0) unwatched,COALESCE(SUM(watched>0 AND watched<total),0) partial FROM counts',$params);
    }
    public function seasons(int $id): array
    {
        $rows=$this->db->fetchAllAssociative('SELECT s.* FROM seasons s WHERE series_id=? ORDER BY season_number,id',[$id]);
        foreach ($rows as &$row) {
            $row['episodes']=$this->episodes((int)$row['id']);
            $row['watched']=count(array_filter($row['episodes'],static fn(array $e)=>(bool)$e['watched']));
            $row['state']=!$row['watched']?'Unwatched':($row['watched']===count($row['episodes'])?'Watched':'Partially watched');
            $row['totals']=$this->totals('SELECT f.* FROM media_files f WHERE EXISTS(SELECT 1 FROM file_content_links l JOIN episodes e ON e.id=l.episode_id WHERE l.file_id=f.id AND e.season_id=?)',[$row['id']]);
        }
        return $rows;
    }
    public function episodes(int $id): array
    {
        return $this->db->fetchAllAssociative("SELECT e.*,COUNT(l.file_id) copies,COALESCE(SUM(f.check_outcome='available'),0) available,COALESCE(SUM(f.check_outcome='missing'),0) missing,COALESCE(SUM(f.check_outcome NOT IN ('available','missing')),0) uncheckable FROM episodes e JOIN file_content_links l ON l.episode_id=e.id JOIN media_files f ON f.id=l.file_id WHERE e.season_id=? GROUP BY e.id ORDER BY e.episode_number,e.id",[$id]);
    }
}
