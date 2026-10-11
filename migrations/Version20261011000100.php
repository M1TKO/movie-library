<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011000100 extends AbstractMigration
{
    public function getDescription(): string { return 'Owner, settings, and media locations foundation'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE owner_auth (id INTEGER PRIMARY KEY CHECK(id = 1), password_hash TEXT NOT NULL, created_at TEXT NOT NULL)');
        $this->addSql("CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)");
        $this->addSql("INSERT INTO settings VALUES ('lan_enabled', 'false'), ('host_actions_enabled', 'false')");
        $this->addSql("CREATE TABLE locations (
            id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL,
            root_path TEXT NOT NULL, normalized_root TEXT NOT NULL UNIQUE,
            enabled INTEGER NOT NULL DEFAULT 1 CHECK(enabled IN (0,1)),
            recursive INTEGER NOT NULL DEFAULT 1 CHECK(recursive IN (0,1)),
            content_hint TEXT NOT NULL DEFAULT 'mixed' CHECK(content_hint IN ('mixed','movies','tv')),
            exclusions TEXT NOT NULL DEFAULT '[]', status TEXT NOT NULL DEFAULT 'not_yet_checked',
            last_error TEXT, last_scan_at TEXT, last_check_at TEXT, created_at TEXT NOT NULL
        )");
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE locations');
        $this->addSql('DROP TABLE settings');
        $this->addSql('DROP TABLE owner_auth');
    }
}
