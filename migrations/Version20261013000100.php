<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Release settings and durable login throttling';
    }
    public function up(Schema $schema): void
    {
        $this->addSql("INSERT OR IGNORE INTO settings VALUES ('lan_address',''),('page_size','50'),('probe_concurrency','2'),('poster_cache_mib','100'),('log_level','errors'),('retention_days','30')");
        $this->addSql('CREATE TABLE login_attempts (identity TEXT PRIMARY KEY, window_start INTEGER NOT NULL, attempts INTEGER NOT NULL)');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE login_attempts');
    }
}
