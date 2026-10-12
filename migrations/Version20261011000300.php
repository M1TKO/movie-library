<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011000300 extends AbstractMigration
{
    public function getDescription(): string { return 'Local candidate identities and configurable discovery defaults'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE movies ADD COLUMN local_identity TEXT');
        $this->addSql('ALTER TABLE series ADD COLUMN local_identity TEXT');
        $this->addSql('CREATE UNIQUE INDEX movies_local_identity ON movies(local_identity) WHERE local_identity IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX series_local_identity ON series(local_identity) WHERE local_identity IS NOT NULL');
        $this->addSql("INSERT OR IGNORE INTO settings(name,value) VALUES ('video_extensions','mkv mp4 avi mov m4v mpg mpeg wmv ts m2ts webm'), ('ffprobe_executable','ffprobe')");
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX movies_local_identity');
        $this->addSql('DROP INDEX series_local_identity');
        $this->addSql('ALTER TABLE movies DROP COLUMN local_identity');
        $this->addSql('ALTER TABLE series DROP COLUMN local_identity');
        $this->addSql("DELETE FROM settings WHERE name IN ('video_extensions','ffprobe_executable')");
    }
}
