<?php
declare(strict_types=1);
namespace App\Catalog;

use App\Location\HostPath;
use Doctrine\DBAL\Connection;

final class CatalogWriter
{
    public function __construct(private readonly Connection $db, private readonly HostPath $paths, private readonly FilenameParser $parser, private readonly ?\App\Metadata\Dispatcher $metadata = null) {}

    public function record(string $runId, array $location, array $record): void
    {
        $this->db->transactional(function () use ($runId, $location, $record): void {
            $path = $this->paths->normalize($record['path']);
            $existing = $this->db->fetchAssociative('SELECT * FROM media_files WHERE normalized_path=?', [$path]);
            $now = gmdate(DATE_ATOM);
            $parsed = $this->parser->parse($record['path'], $location['content_hint']);
            $changed = $existing && ((int)$existing['size_bytes'] !== $record['size'] || (int)$existing['modified_at'] !== $record['mtime']);
            $outcome = !$existing ? 'added' : ($existing['last_known_availability'] === 'missing' ? 'restored' : ($changed ? 'changed' : 'unchanged'));
            $values = ['original_path'=>$record['path'],'filename'=>basename($record['path']),'size_bytes'=>$record['size'],'modified_at'=>$record['mtime'],
                'last_known_availability'=>'available','check_outcome'=>'available','check_error'=>null,'last_seen_at'=>$now,'last_checked_at'=>$now,'updated_at'=>$now];
            if ($record['probe'] !== null) {
                // Failed or unstable inspections must not retain stale technical values.
                $values += array_merge(array_fill_keys(['width','height','duration_seconds','video_codec','frame_rate','audio_codec','audio_channels'], null), $record['probe']);
            }
            if ($existing) {
                $fileId = (int)$existing['id'];
                $this->db->update('media_files', $values, ['id'=>$fileId]);
            } else {
                $this->db->insert('media_files', $values + ['normalized_path'=>$path,'parsed_fields'=>json_encode($parsed, JSON_THROW_ON_ERROR),'created_at'=>$now]);
                $fileId = (int)$this->db->lastInsertId();
            }
            $this->db->executeStatement('INSERT INTO file_locations(file_id,location_id,relative_path,in_scope) VALUES(?,?,?,1) ON CONFLICT(file_id,location_id) DO UPDATE SET relative_path=excluded.relative_path,in_scope=1', [$fileId,$location['id'],$record['relative']]);
            if (!(int)$this->db->fetchOne('SELECT COUNT(*) FROM file_content_links WHERE file_id=?', [$fileId])) { $this->link($fileId, $parsed, $now); }
            $inserted = $this->db->executeStatement('INSERT OR IGNORE INTO run_file_results(run_id,file_id,file_identity,outcome,path_snapshot,current_state,diagnostic_code,details) VALUES(?,?,?,?,?,?,?,?)',
                [$runId,$fileId,$path,$outcome,$record['path'],'available',$record['probe']['probe_state'] ?? null,$record['probe']['probe_error'] ?? null]);
            if ($inserted) {
                $this->db->executeStatement('UPDATE runs SET examined=examined+1, '.$outcome.'='.$outcome.'+1 WHERE id=?', [$runId]);
                if ($outcome !== 'unchanged') {
                    $this->db->insert('events', ['occurred_at'=>$now,'event_type'=>$outcome,'run_id'=>$runId,'file_id'=>$fileId,'path_snapshot'=>$record['path'],
                        'before_values'=>$existing ? json_encode(['size_bytes'=>$existing['size_bytes'],'modified_at'=>$existing['modified_at'],'availability'=>$existing['last_known_availability']], JSON_THROW_ON_ERROR) : null,
                        'after_values'=>json_encode(['size_bytes'=>$record['size'],'modified_at'=>$record['mtime'],'availability'=>'available'], JSON_THROW_ON_ERROR)]);
                }
            }
        });
    }

    private function link(int $fileId, array $parsed, string $now): void
    {
        if ($parsed['type'] === 'unresolved') { return; }
        $table = $parsed['type'] === 'movie' ? 'movies' : 'series';
        $identity = hash('sha256', mb_strtolower($parsed['title'], 'UTF-8').'|'.($parsed['year'] ?? 'unknown'));
        $alias = $this->db->fetchOne('SELECT title_id FROM title_aliases WHERE content_type=? AND local_identity=?', [$table,$identity]);
        if (!$alias) { $this->db->executeStatement('INSERT OR IGNORE INTO '.$table.'(title,release_year,local_identity,metadata_source,created_at,updated_at) VALUES(?,?,?,?,?,?)', [$parsed['title'],$parsed['year'],$identity,'filename',$now,$now]); }
        $titleId = $alias ? (int)$alias : (int)$this->db->fetchOne('SELECT id FROM '.$table.' WHERE local_identity=?', [$identity]);
        if ($table === 'movies') { $this->db->insert('file_content_links', ['file_id'=>$fileId,'movie_id'=>$titleId]); $this->metadata?->dispatch($table,$titleId); return; }
        $this->db->executeStatement('INSERT OR IGNORE INTO seasons(series_id,season_number) VALUES(?,?)', [$titleId,$parsed['season']]);
        $seasonId = (int)$this->db->fetchOne('SELECT id FROM seasons WHERE series_id=? AND season_number=?', [$titleId,$parsed['season']]);
        foreach ($parsed['episodes'] as $episode) {
            $this->db->executeStatement('INSERT OR IGNORE INTO episodes(season_id,episode_number,title,created_at,updated_at) VALUES(?,?,?,?,?)', [$seasonId,$episode,'Episode '.$episode,$now,$now]);
            $episodeId = (int)$this->db->fetchOne('SELECT id FROM episodes WHERE season_id=? AND episode_number=?', [$seasonId,$episode]);
            $this->db->insert('file_content_links', ['file_id'=>$fileId,'episode_id'=>$episodeId]);
        }
        $this->metadata?->dispatch($table,$titleId);
    }
}
