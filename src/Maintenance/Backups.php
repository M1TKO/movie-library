<?php

declare(strict_types=1);

namespace App\Maintenance;

use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** Consistent catalog snapshots; never opens media paths. */
final class Backups
{
    private const OMIT = ['owner_auth','messenger_messages','worker_health','worker_receipts','metadata_cache','login_attempts'];
    public function __construct(private readonly Connection $db) {}
    public function create(): string
    {
        $base = $_SERVER['APP_DATA_DIR'] . '/backups';
        if (!is_dir($base) && !mkdir($base, 0700, true)) {
            throw new \RuntimeException('Cannot create backup directory.');
        }
        $dir = $base . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700)) {
            throw new \RuntimeException('Cannot create backup.');
        }
        $path = $dir . '/catalog.sqlite';
        try {
            $this->db->executeStatement('VACUUM INTO ' . $this->db->quote($path));
            chmod($path, 0600);
            $copy = new \PDO('sqlite:' . $path);
            $copy->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            foreach (self::OMIT as $table) {
                $copy->exec('DELETE FROM "' . $table . '"');
            }
            $copy->exec("UPDATE settings SET value='false' WHERE name IN ('lan_enabled','host_actions_enabled')");
            $copy->exec('VACUUM');
            $copy = null;
            $manifest = ['format' => 1,'application' => 'Movie Library','application_version' => '1.0.0','created_at' => gmdate(DATE_ATOM),
                'sha256' => hash_file('sha256', $path),'schema' => $this->schema($this->db->getNativeConnection()),'excluded' => self::OMIT];
            file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
            chmod($dir . '/manifest.json', 0600);
            return $dir;
        } catch (\Throwable $e) {
            foreach (['catalog.sqlite','manifest.json'] as $file) {
                if (is_file($dir . '/' . $file)) {
                    unlink($dir . '/' . $file);
                }
            }
            rmdir($dir);
            throw $e;
        }
    }
    public function restore(string $directory, string $password): string
    {
        $hash = $this->db->fetchOne('SELECT password_hash FROM owner_auth WHERE id=1');
        if (!$hash || !password_verify($password, $hash)) {
            throw new \RuntimeException('Owner authentication failed.');
        }
        $locks = [];
        $attached = false;
        $marked = false;
        $maintenance = $_SERVER['APP_DATA_DIR'] . '/maintenance';
        try {
            foreach (['catalog-restore','application-supervisor','worker-filesystem','worker-metadata','filesystem-job','metadata-job'] as $name) {
                $lock = (new LockFactory(new FlockStore($_SERVER['APP_DATA_DIR'])))->createLock($name);
                if (!$lock->acquire()) {
                    throw new \RuntimeException('Stop the application and all workers before restoring.');
                }
                $locks[] = $lock;
            }
            $path = realpath($directory . '/catalog.sqlite');
            $mp = $directory . '/manifest.json';
            if (!$path || !is_file($mp) || filesize($mp) > 65536) {
                throw new \RuntimeException('Backup files are missing or invalid.');
            }
            $manifest = json_decode((string) file_get_contents($mp), true, 32, JSON_THROW_ON_ERROR);
            if (($manifest['format'] ?? null) !== 1 || ($manifest['application'] ?? null) !== 'Movie Library' || !hash_equals((string) ($manifest['sha256'] ?? ''), (string) hash_file('sha256', $path))) {
                throw new \RuntimeException('Backup version or checksum is invalid.');
            }
            $source = new \PDO('sqlite:file:' . str_replace('\\', '/', $path) . '?mode=ro');
            $source->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $schema = $this->schema($source);
            if ($schema !== ($manifest['schema'] ?? null) || $schema !== $this->schema($this->db->getNativeConnection())) {
                throw new \RuntimeException('Backup schema is incompatible. Use the matching application version.');
            }
            if ($source->query('PRAGMA integrity_check')->fetchColumn() !== 'ok' || $source->query('PRAGMA foreign_key_check')->fetch()) {
                throw new \RuntimeException('Backup integrity validation failed.');
            }
            foreach (self::OMIT as $table) {
                if ((int) $source->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn()) {
                    throw new \RuntimeException('Backup contains excluded authentication or runtime data.');
                }
            }
            $source = null;
            file_put_contents($maintenance, 'Catalog restore in progress', LOCK_EX);
            $marked = true;
            $before = $this->create();
            $this->db->executeStatement('ATTACH DATABASE ? AS restore_source', [$path]);
            $attached = true;
            $tables = $this->db->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
            $this->db->executeStatement('PRAGMA foreign_keys=OFF');
            try {
                $this->db->transactional(function () use ($tables): void {
                    // Atomic SQLite commit avoids replacing an open database inode on either OS.
                    foreach ($tables as $table) {
                        if ($table === 'owner_auth') {
                            continue;
                        }
                        $quoted = $this->db->quoteIdentifier($table);
                        $this->db->executeStatement('DELETE FROM ' . $quoted);
                        if (!in_array($table, self::OMIT, true)) {
                            $this->db->executeStatement('INSERT INTO ' . $quoted . ' SELECT * FROM restore_source.' . $quoted);
                        }
                    }
                    $this->db->executeStatement("UPDATE runs SET state='interrupted',finished_at=?,summary='Restored catalog: retry manually.',enrichment_pending=0 WHERE state IN ('queued','running')", [gmdate(DATE_ATOM)]);
                    $this->db->executeStatement("UPDATE run_locations SET enumeration_outcome='interrupted' WHERE enumeration_outcome IN ('queued','running')");
                    $this->db->executeStatement("UPDATE settings SET value='false' WHERE name IN ('lan_enabled','host_actions_enabled')");
                    if ($this->db->fetchAllAssociative('PRAGMA foreign_key_check')) {
                        throw new \RuntimeException('Restored relationships are invalid. Changes rolled back.');
                    }
                });
            } finally {
                $this->db->executeStatement('PRAGMA foreign_keys=ON');
            }
            return $before;
        } finally {
            if ($attached) {
                $this->db->executeStatement('DETACH DATABASE restore_source');
            }
            if ($marked) {
                unlink($maintenance);
            }
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }
    private function schema(\PDO $db): array
    {
        return $db->query("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' AND sql IS NOT NULL ORDER BY type,name")->fetchAll(\PDO::FETCH_ASSOC);
    }
}
