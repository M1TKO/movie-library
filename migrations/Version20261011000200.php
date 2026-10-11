<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011000200 extends AbstractMigration
{
    public function getDescription(): string { return 'Catalog identities, copies, audit history and durable worker infrastructure'; }

    public function up(Schema $schema): void
    {
        $metadata = "title TEXT NOT NULL, original_title TEXT, release_year INTEGER, synopsis TEXT, genres TEXT NOT NULL DEFAULT '[]', tmdb_id INTEGER UNIQUE, imdb_id TEXT, poster_path TEXT, metadata_language TEXT, metadata_source TEXT, metadata_refreshed_at TEXT, matching_state TEXT NOT NULL DEFAULT 'pending' CHECK(matching_state IN ('pending','auto_matched','manually_confirmed','needs_review','unmatched')), manual_lock INTEGER NOT NULL DEFAULT 0 CHECK(manual_lock IN (0,1))";
        $watched = "watched INTEGER NOT NULL DEFAULT 0 CHECK(watched IN (0,1)), watched_at TEXT";
        $this->addSql("CREATE TABLE movies (id INTEGER PRIMARY KEY AUTOINCREMENT, $metadata, $watched, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
        $this->addSql("CREATE TABLE series (id INTEGER PRIMARY KEY AUTOINCREMENT, $metadata, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
        $this->addSql('CREATE TABLE seasons (id INTEGER PRIMARY KEY AUTOINCREMENT, series_id INTEGER NOT NULL REFERENCES series(id), season_number INTEGER NOT NULL CHECK(season_number >= 0), tmdb_id INTEGER UNIQUE, title TEXT, UNIQUE(series_id, season_number))');
        $this->addSql("CREATE TABLE episodes (id INTEGER PRIMARY KEY AUTOINCREMENT, season_id INTEGER NOT NULL REFERENCES seasons(id), episode_number INTEGER NOT NULL CHECK(episode_number >= 0), title TEXT NOT NULL, air_date TEXT, tmdb_id INTEGER UNIQUE, imdb_id TEXT, $watched, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE(season_id, episode_number))");
        $this->addSql("CREATE TABLE media_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT, original_path TEXT NOT NULL, normalized_path TEXT NOT NULL UNIQUE,
            filename TEXT NOT NULL, parsed_fields TEXT NOT NULL DEFAULT '{}', size_bytes INTEGER CHECK(size_bytes >= 0), modified_at INTEGER,
            width INTEGER, height INTEGER, duration_seconds REAL, video_codec TEXT, frame_rate REAL, audio_codec TEXT, audio_channels INTEGER,
            last_known_availability TEXT CHECK(last_known_availability IN ('available','missing')),
            check_outcome TEXT NOT NULL DEFAULT 'not_yet_checked' CHECK(check_outcome IN ('available','missing','location_offline','access_denied','check_failed','not_monitored','not_yet_checked')),
            check_error TEXT, last_seen_at TEXT, last_checked_at TEXT,
            probe_state TEXT NOT NULL DEFAULT 'pending' CHECK(probe_state IN ('pending','complete','failed','retry')), probe_error TEXT, probed_at TEXT,
            created_at TEXT NOT NULL, updated_at TEXT NOT NULL
        )");
        $this->addSql('CREATE TABLE file_locations (file_id INTEGER NOT NULL REFERENCES media_files(id) ON DELETE CASCADE, location_id INTEGER NOT NULL REFERENCES locations(id) ON DELETE CASCADE, relative_path TEXT NOT NULL, in_scope INTEGER NOT NULL DEFAULT 1 CHECK(in_scope IN (0,1)), PRIMARY KEY(file_id, location_id))');
        $this->addSql('CREATE TABLE file_content_links (id INTEGER PRIMARY KEY AUTOINCREMENT, file_id INTEGER NOT NULL REFERENCES media_files(id) ON DELETE CASCADE, movie_id INTEGER REFERENCES movies(id), episode_id INTEGER REFERENCES episodes(id), CHECK((movie_id IS NOT NULL) + (episode_id IS NOT NULL) = 1))');
        $this->addSql('CREATE UNIQUE INDEX file_movie_identity ON file_content_links(file_id, movie_id) WHERE movie_id IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX file_episode_identity ON file_content_links(file_id, episode_id) WHERE episode_id IS NOT NULL');
        $this->addSql("CREATE TABLE runs (
            id TEXT PRIMARY KEY, job_type TEXT NOT NULL CHECK(job_type IN ('discovery','availability','enrichment','diagnostic')),
            trigger_name TEXT NOT NULL, state TEXT NOT NULL DEFAULT 'queued' CHECK(state IN ('queued','running','completed','completed_with_errors','cancelled','failed','interrupted')),
            deduplication_key TEXT, options TEXT NOT NULL DEFAULT '{}', queued_at TEXT NOT NULL, started_at TEXT, finished_at TEXT,
            heartbeat_at TEXT, owner_token TEXT, cancel_requested INTEGER NOT NULL DEFAULT 0 CHECK(cancel_requested IN (0,1)),
            examined INTEGER NOT NULL DEFAULT 0, added INTEGER NOT NULL DEFAULT 0, changed INTEGER NOT NULL DEFAULT 0,
            newly_missing INTEGER NOT NULL DEFAULT 0, restored INTEGER NOT NULL DEFAULT 0, unchanged INTEGER NOT NULL DEFAULT 0, uncheckable INTEGER NOT NULL DEFAULT 0,
            enrichment_pending INTEGER NOT NULL DEFAULT 0, enrichment_matched INTEGER NOT NULL DEFAULT 0, enrichment_review INTEGER NOT NULL DEFAULT 0, enrichment_failed INTEGER NOT NULL DEFAULT 0,
            summary TEXT
        )");
        $this->addSql("CREATE UNIQUE INDEX runs_active_deduplication ON runs(deduplication_key) WHERE state IN ('queued','running') AND deduplication_key IS NOT NULL");
        $this->addSql('CREATE TABLE run_locations (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id TEXT NOT NULL REFERENCES runs(id) ON DELETE CASCADE, location_id INTEGER REFERENCES locations(id) ON DELETE SET NULL, root_snapshot TEXT NOT NULL, accessibility TEXT NOT NULL, enumeration_outcome TEXT, error_code TEXT, error_reason TEXT, UNIQUE(run_id, location_id))');
        $this->addSql("CREATE TABLE run_file_results (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id TEXT NOT NULL REFERENCES runs(id) ON DELETE CASCADE, file_id INTEGER REFERENCES media_files(id) ON DELETE SET NULL, file_identity TEXT NOT NULL, outcome TEXT NOT NULL CHECK(outcome IN ('added','changed','newly_missing','restored','unchanged','uncheckable')), path_snapshot TEXT NOT NULL, current_state TEXT, diagnostic_code TEXT, details TEXT, UNIQUE(run_id, file_identity))");
        $this->addSql('CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, occurred_at TEXT NOT NULL, event_type TEXT NOT NULL, run_id TEXT REFERENCES runs(id) ON DELETE SET NULL, file_id INTEGER REFERENCES media_files(id) ON DELETE SET NULL, movie_id INTEGER REFERENCES movies(id) ON DELETE SET NULL, episode_id INTEGER REFERENCES episodes(id) ON DELETE SET NULL, title_snapshot TEXT, path_snapshot TEXT, before_values TEXT, after_values TEXT, reason TEXT)');
        $this->addSql("CREATE TABLE match_candidates (id INTEGER PRIMARY KEY AUTOINCREMENT, file_id INTEGER REFERENCES media_files(id) ON DELETE CASCADE, movie_id INTEGER REFERENCES movies(id) ON DELETE CASCADE, series_id INTEGER REFERENCES series(id) ON DELETE CASCADE, provider TEXT NOT NULL, provider_id TEXT NOT NULL, content_type TEXT NOT NULL, summary TEXT NOT NULL, score REAL NOT NULL, evidence TEXT NOT NULL, review_state TEXT NOT NULL DEFAULT 'pending', CHECK((file_id IS NOT NULL) + (movie_id IS NOT NULL) + (series_id IS NOT NULL) = 1))");
        $this->addSql('CREATE TABLE metadata_cache (provider TEXT NOT NULL, request_identity TEXT NOT NULL, payload TEXT NOT NULL, fetched_at TEXT NOT NULL, expires_at TEXT, cache_state TEXT NOT NULL, PRIMARY KEY(provider, request_identity))');
        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX messenger_queue ON messenger_messages(queue_name, available_at, delivered_at, id)');
        $this->addSql("CREATE TABLE worker_health (name TEXT PRIMARY KEY, instance_id TEXT NOT NULL, pid INTEGER NOT NULL, state TEXT NOT NULL, started_at TEXT NOT NULL, heartbeat_at TEXT NOT NULL, last_message_at TEXT, last_error TEXT)");
        $this->addSql('CREATE TABLE worker_receipts (id TEXT PRIMARY KEY, queue_name TEXT NOT NULL, completed_at TEXT NOT NULL)');
        foreach (['movies' => ['title', 'watched', 'matching_state'], 'series' => ['title'], 'episodes' => ['watched'], 'media_files' => ['filename', 'check_outcome', 'last_checked_at'], 'file_locations' => ['location_id'], 'file_content_links' => ['movie_id', 'episode_id'], 'runs' => ['queued_at'], 'events' => ['occurred_at', 'file_id', 'movie_id', 'episode_id'], 'run_file_results' => ['file_id']] as $table => $columns) {
            foreach ($columns as $column) { $this->addSql("CREATE INDEX idx_{$table}_{$column} ON $table($column)"); }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['worker_receipts', 'worker_health', 'messenger_messages', 'metadata_cache', 'match_candidates', 'events', 'run_file_results', 'run_locations', 'runs', 'file_content_links', 'file_locations', 'media_files', 'episodes', 'seasons', 'series', 'movies'] as $table) {
            $this->addSql("DROP TABLE $table");
        }
    }
}
