<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Covering catalog association indexes for large-library filtering';
    }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX links_file ON file_content_links(file_id)');
        $this->addSql('CREATE INDEX links_movie_file ON file_content_links(movie_id,file_id)');
        $this->addSql('CREATE INDEX links_episode_file ON file_content_links(episode_id,file_id)');
    }
    public function down(Schema $schema): void
    {
        foreach (['links_file','links_movie_file','links_episode_file'] as $name) {
            $this->addSql('DROP INDEX ' . $name);
        }
    }
}
