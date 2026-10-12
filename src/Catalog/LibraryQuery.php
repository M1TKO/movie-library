<?php
declare(strict_types=1);
namespace App\Catalog;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
final class LibraryQuery
{
    public function __construct(private readonly Connection $db) {}
    public function browse(Request $request): array
    {
        $pick = static fn(string $key, array $allowed, string $default) => in_array($request->query->getString($key, $default), $allowed, true) ? $request->query->getString($key, $default) : $default;
        $view = $pick('view', ['files','unresolved','movies','series'], 'files');
        $layout = $pick('layout', ['table','posters'], 'table');
        $sort = $pick('sort', ['title','year','added','size','checked'], 'title');
        $direction = $pick('direction', ['asc','desc'], 'asc');
        $availability = $pick('availability', ['','available','missing','uncheckable'], '');
        $matching = $pick('matching', ['','pending','auto_matched','manually_confirmed','needs_review','unmatched'], '');
        $watched = $pick('watched', ['','watched','unwatched','partial'], '');
        $resolution = $pick('resolution', ['','unknown','720','1080','2160'], '');
        $perPage = (int)$pick('per_page', ['25','50','100'], '50');
        $q = mb_substr(trim($request->query->getString('q')), 0, 200);
        $location = max(0, $request->query->getInt('location'));
        $params = []; $where = [];
        $titleView = in_array($view, ['movies','series'], true);
        $fileLink = $view === 'movies' ? 'SELECT l.file_id FROM file_content_links l WHERE l.movie_id=t.id' : 'SELECT l.file_id FROM file_content_links l JOIN episodes e ON e.id=l.episode_id JOIN seasons s ON s.id=e.season_id WHERE s.series_id=t.id';
        $fileTitles = 'SELECT m.title,m.original_title,m.matching_state,m.watched FROM file_content_links l JOIN movies m ON m.id=l.movie_id WHERE l.file_id=f.id UNION ALL SELECT s.title,s.original_title,s.matching_state,e.watched FROM file_content_links l JOIN episodes e ON e.id=l.episode_id JOIN seasons se ON se.id=e.season_id JOIN series s ON s.id=se.series_id WHERE l.file_id=f.id';
        $fileFilters = [];
        if ($location) { $fileFilters[] = 'EXISTS(SELECT 1 FROM file_locations fl WHERE fl.file_id=f.id AND fl.location_id=:location)'; $params['location']=$location; }
        if ($resolution) {
            $fileFilters[] = match ($resolution) { 'unknown'=>'f.height IS NULL', '720'=>'f.height BETWEEN 1 AND 1079', '1080'=>'f.height BETWEEN 1080 AND 2159', '2160'=>'f.height>=2160' };
        }
        if ($availability) { $fileFilters[] = $availability === 'uncheckable' ? "f.check_outcome NOT IN ('available','missing')" : 'f.check_outcome=:availability'; if ($availability !== 'uncheckable') { $params['availability']=$availability; } }
        if ($titleView) {
            $source = 'SELECT t.*, (SELECT MAX(f.size_bytes) FROM media_files f WHERE f.id IN ('.$fileLink.')) sort_size, (SELECT MAX(f.last_checked_at) FROM media_files f WHERE f.id IN ('.$fileLink.')) sort_checked FROM '.$view.' t';
            if ($q !== '') { $where[]='(t.title LIKE :q ESCAPE \'!\' OR t.original_title LIKE :q ESCAPE \'!\' OR EXISTS(SELECT 1 FROM media_files f WHERE f.id IN ('.$fileLink.') AND f.filename LIKE :q ESCAPE \'!\'))'; }
            if ($matching) { $where[]='t.matching_state=:matching'; $params['matching']=$matching; }
            if ($watched) {
                if ($view === 'movies') { $where[]=$watched==='partial' ? '0=1' : 't.watched='.($watched==='watched'?1:0); }
                else {
                    $episodes='SELECT e.watched FROM episodes e JOIN seasons s ON s.id=e.season_id WHERE s.series_id=t.id AND EXISTS(SELECT 1 FROM file_content_links l WHERE l.episode_id=e.id)';
                    $hasWatched='EXISTS(SELECT 1 FROM ('.$episodes.') ew WHERE ew.watched=1)';
                    $hasUnwatched='EXISTS(SELECT 1 FROM ('.$episodes.') ew WHERE ew.watched=0)';
                    $where[]=match($watched) { 'watched'=>$hasWatched.' AND NOT '.$hasUnwatched, 'unwatched'=>$hasUnwatched.' AND NOT '.$hasWatched, 'partial'=>$hasWatched.' AND '.$hasUnwatched };
                }
            }
            if ($fileFilters) { $where[]='EXISTS(SELECT 1 FROM media_files f WHERE f.id IN ('.$fileLink.') AND '.implode(' AND ', $fileFilters).')'; }
            $orders=['title'=>'t.title COLLATE NOCASE','year'=>'t.release_year','added'=>'t.created_at','size'=>'sort_size','checked'=>'sort_checked'];
        } else {
            $source='SELECT f.* FROM media_files f';
            if ($view==='unresolved') { $where[]='NOT EXISTS(SELECT 1 FROM file_content_links l WHERE l.file_id=f.id)'; }
            if ($q !== '') { $where[]="(f.filename LIKE :q ESCAPE '!' OR EXISTS(SELECT 1 FROM (".$fileTitles.") ft WHERE ft.title LIKE :q ESCAPE '!' OR ft.original_title LIKE :q ESCAPE '!'))"; }
            if ($matching) { $where[]='EXISTS(SELECT 1 FROM ('.$fileTitles.') ft WHERE ft.matching_state=:matching)'; $params['matching']=$matching; }
            if ($watched) { $where[]=$watched==='partial' ? '0=1' : 'EXISTS(SELECT 1 FROM ('.$fileTitles.') ft WHERE ft.watched='.($watched==='watched'?1:0).')'; }
            $where=array_merge($where,$fileFilters);
            $orders=['title'=>'f.filename COLLATE NOCASE','year'=>"json_extract(f.parsed_fields,'$.year')",'added'=>'f.created_at','size'=>'f.size_bytes','checked'=>'f.last_checked_at'];
        }
        if ($q !== '') { $params['q']='%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%'; }
        $filtered=$source.($where ? ' WHERE '.implode(' AND ', $where) : '');
        $total=(int)$this->db->fetchOne('SELECT COUNT(*) FROM ('.$filtered.') result', $params);
        $page=max(1,min($request->query->getInt('page',1),max(1,(int)ceil($total/$perPage))));
        $rows=$this->db->fetchAllAssociative($filtered.' ORDER BY '.$orders[$sort].' '.strtoupper($direction).', '.($titleView?'t':'f').'.id '.strtoupper($direction).' LIMIT '.$perPage.' OFFSET '.(($page-1)*$perPage),$params);
        foreach ($rows as &$row) {
            $row['poster_key']=$titleView ? \App\Metadata\TmdbProvider::posterKey($row['poster_path']) : null;
            if ($view==='movies') { $row['watched_summary']=$row['watched']?'Watched':'Unwatched'; }
            elseif ($view==='series') {
                $counts=$this->db->fetchAssociative('SELECT COUNT(*) total,COALESCE(SUM(e.watched),0) watched FROM episodes e JOIN seasons s ON s.id=e.season_id WHERE s.series_id=? AND EXISTS(SELECT 1 FROM file_content_links l WHERE l.episode_id=e.id)',[$row['id']]);
                $row['watched_summary']=!$counts['watched']?'Unwatched':($counts['total']===$counts['watched']?'Watched':'Partially watched');
            }
        } unset($row);
        if ($titleView) {
            $links=$view==='movies' ? 'SELECT l.file_id FROM file_content_links l WHERE l.movie_id IN (SELECT id FROM selected)' : 'SELECT l.file_id FROM file_content_links l JOIN episodes e ON e.id=l.episode_id JOIN seasons s ON s.id=e.season_id WHERE s.series_id IN (SELECT id FROM selected)';
            $physical='WITH selected AS ('.$filtered.') SELECT f.* FROM media_files f WHERE f.id IN ('.$links.')';
        } else { $physical=$filtered; }
        $totals=$this->db->fetchAssociative("SELECT COUNT(*) files, COALESCE(SUM(CASE WHEN check_outcome='available' THEN 1 ELSE 0 END),0) available, COALESCE(SUM(CASE WHEN check_outcome='missing' THEN 1 ELSE 0 END),0) missing, COALESCE(SUM(CASE WHEN check_outcome NOT IN ('available','missing') THEN 1 ELSE 0 END),0) uncheckable, COALESCE(SUM(size_bytes),0) bytes, COALESCE(SUM(CASE WHEN check_outcome='available' THEN size_bytes ELSE 0 END),0) available_bytes FROM (".$physical.') physical',$params);
        if ($titleView && $view==='movies') {
            $watchedSql='WITH selected AS ('.$filtered.') SELECT watched FROM selected';
        } elseif ($titleView) {
            $watchedSql='WITH selected AS ('.$filtered.') SELECT e.watched FROM episodes e JOIN seasons s ON s.id=e.season_id WHERE s.series_id IN (SELECT id FROM selected) AND EXISTS(SELECT 1 FROM file_content_links l WHERE l.episode_id=e.id)';
        } else {
            $watchedSql='WITH selected AS ('.$filtered.') SELECT m.watched FROM movies m WHERE EXISTS(SELECT 1 FROM file_content_links l WHERE l.movie_id=m.id AND l.file_id IN (SELECT id FROM selected)) UNION ALL SELECT e.watched FROM episodes e WHERE EXISTS(SELECT 1 FROM file_content_links l WHERE l.episode_id=e.id AND l.file_id IN (SELECT id FROM selected))';
        }
        $watchedTotals=$this->db->fetchAssociative('SELECT COUNT(*) titles,COALESCE(SUM(watched),0) watched,COALESCE(SUM(watched=0),0) unwatched FROM ('.$watchedSql.') content',$params);
        $seriesStates=$view==='series'?(new Experience($this->db))->seriesStates($filtered,$params):null;
        return compact('rows','total','page','view','layout','sort','direction','availability','matching','watched','resolution','q','location','perPage','totals','watchedTotals','seriesStates') + ['filters'=>['view'=>$view,'layout'=>$layout,'sort'=>$sort,'direction'=>$direction,'availability'=>$availability,'matching'=>$matching,'watched'=>$watched,'resolution'=>$resolution,'q'=>$q,'location'=>$location,'per_page'=>$perPage], 'locations'=>$this->db->fetchAllAssociative('SELECT id,label FROM locations ORDER BY label,id')];
    }
}
