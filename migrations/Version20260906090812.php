<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260906090812 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE task ADD COLUMN deleted_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__task AS SELECT id, name, description, schedule, timezone, status, priority, created_at, updated_at, next_run_at FROM task');
        $this->addSql('DROP TABLE task');
        $this->addSql('CREATE TABLE task (id BLOB NOT NULL, name VARCHAR(255) NOT NULL, description CLOB NOT NULL, schedule VARCHAR(255) DEFAULT NULL, timezone VARCHAR(64) DEFAULT \'UTC\' NOT NULL, status VARCHAR(16) DEFAULT \'draft\' NOT NULL, priority INTEGER DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, next_run_at DATETIME DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO task (id, name, description, schedule, timezone, status, priority, created_at, updated_at, next_run_at) SELECT id, name, description, schedule, timezone, status, priority, created_at, updated_at, next_run_at FROM __temp__task');
        $this->addSql('DROP TABLE __temp__task');
    }
}
