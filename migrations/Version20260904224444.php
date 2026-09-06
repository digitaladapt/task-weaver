<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260904224444 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE event (id BLOB NOT NULL, api_key VARCHAR(255) DEFAULT NULL, type VARCHAR(32) NOT NULL, payload CLOB NOT NULL, timestamp DATETIME NOT NULL, step_id BLOB NOT NULL, task_id BLOB NOT NULL, worker_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_3BAE0AA773B21E9C FOREIGN KEY (step_id) REFERENCES step (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3BAE0AA78DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3BAE0AA76B20BA36 FOREIGN KEY (worker_id) REFERENCES worker (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_event_step ON event (step_id)');
        $this->addSql('CREATE INDEX idx_event_task ON event (task_id)');
        $this->addSql('CREATE INDEX idx_event_worker ON event (worker_id)');
        $this->addSql('CREATE TABLE mcp_server (id BLOB NOT NULL, name VARCHAR(255) NOT NULL, transport VARCHAR(32) NOT NULL, endpoint VARCHAR(2048) NOT NULL, cred_vars CLOB NOT NULL, enabled BOOLEAN DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE step (id BLOB NOT NULL, name VARCHAR(255) NOT NULL, description CLOB NOT NULL, tags CLOB NOT NULL, is_final BOOLEAN NOT NULL, sort_order INTEGER DEFAULT 0 NOT NULL, status VARCHAR(16) DEFAULT \'pending\' NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, result CLOB DEFAULT NULL, task_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_43B9FE3C8DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_43B9FE3C8DB60186 ON step (task_id)');
        $this->addSql('CREATE TABLE task (id BLOB NOT NULL, name VARCHAR(255) NOT NULL, description CLOB NOT NULL, schedule VARCHAR(255) DEFAULT NULL, timezone VARCHAR(64) DEFAULT \'UTC\' NOT NULL, status VARCHAR(16) DEFAULT \'draft\' NOT NULL, priority INTEGER DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, next_run_at DATETIME DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE tool_call (id BLOB NOT NULL, tool_name VARCHAR(255) NOT NULL, request CLOB NOT NULL, response CLOB DEFAULT NULL, error CLOB DEFAULT NULL, status VARCHAR(64) DEFAULT NULL, idempotency_key VARCHAR(255) DEFAULT NULL, timestamp DATETIME NOT NULL, event_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_17CC6F7571F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_toolcall_event ON tool_call (event_id)');
        $this->addSql('CREATE INDEX idx_toolcall_idem ON tool_call (idempotency_key)');
        $this->addSql('CREATE TABLE tool_def (id BLOB NOT NULL, name VARCHAR(255) NOT NULL, tags CLOB NOT NULL, schema CLOB NOT NULL, description CLOB DEFAULT NULL, server_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_F54D7C4B1844E6B7 FOREIGN KEY (server_id) REFERENCES mcp_server (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_tooldef_server ON tool_def (server_id)');
        $this->addSql('CREATE TABLE worker (id BLOB NOT NULL, name VARCHAR(255) NOT NULL, api_key VARCHAR(255) DEFAULT NULL, tags CLOB NOT NULL, internal_tools CLOB NOT NULL, config CLOB NOT NULL, created_at DATETIME NOT NULL, last_seen_at DATETIME DEFAULT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE event');
        $this->addSql('DROP TABLE mcp_server');
        $this->addSql('DROP TABLE step');
        $this->addSql('DROP TABLE task');
        $this->addSql('DROP TABLE tool_call');
        $this->addSql('DROP TABLE tool_def');
        $this->addSql('DROP TABLE worker');
    }
}
