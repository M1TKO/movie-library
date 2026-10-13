<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Metadata settings, identity aliases and series history';
    }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events ADD COLUMN series_id INTEGER REFERENCES series(id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_events_series_id ON events(series_id)');
        $this->addSql('CREATE TABLE title_redirects (content_type TEXT NOT NULL, source_id INTEGER NOT NULL, target_id INTEGER NOT NULL, PRIMARY KEY(content_type,source_id))');
        $this->addSql('CREATE TABLE title_aliases (content_type TEXT NOT NULL, local_identity TEXT NOT NULL, title_id INTEGER NOT NULL, PRIMARY KEY(content_type,local_identity))');
        $this->addSql("INSERT OR IGNORE INTO settings(name,value) VALUES ('metadata_language','en-US')");
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE title_redirects');
        $this->addSql('DROP TABLE title_aliases');
        $this->addSql('DROP INDEX idx_events_series_id');
        $this->addSql('ALTER TABLE events DROP COLUMN series_id');
        $this->addSql("DELETE FROM settings WHERE name='metadata_language'");
    }
}
