<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add server description + tool removal flag for external-tool management.
 *
 * - mcp_server.description  : optional human note for the admin UI.
 * - tool_def.removed_at     : set when a tool disappears from its server's
 *   definition on a re-sync; null = currently defined. Flagged (not deleted)
 *   so manual tags and history are preserved.
 */
final class Version20260907090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mcp_server.description and tool_def.removed_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mcp_server ADD COLUMN description CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE tool_def ADD COLUMN removed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // SQLite: recreate the table without the new column.
        $this->addSql('CREATE TEMPORARY TABLE __temp__mcp_server AS SELECT id, name, transport, endpoint, cred_vars, enabled, created_at FROM mcp_server');
        $this->addSql('DROP TABLE mcp_server');
        $this->addSql('CREATE TABLE mcp_server (id BLOB NOT NULL, name VARCHAR(255) NOT NULL, transport VARCHAR(32) NOT NULL, endpoint VARCHAR(2048) NOT NULL, cred_vars CLOB NOT NULL, enabled BOOLEAN DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO mcp_server (id, name, transport, endpoint, cred_vars, enabled, created_at) SELECT id, name, transport, endpoint, cred_vars, enabled, created_at FROM __temp__mcp_server');
        $this->addSql('DROP TABLE __temp__mcp_server');

        $this->addSql('CREATE TEMPORARY TABLE __temp__tool_def AS SELECT id, name, tags, schema, description, server_id FROM tool_def');
        $this->addSql('DROP TABLE tool_def');
        $this->addSql('CREATE TABLE tool_def (id BLOB NOT NULL, name VARCHAR(255) NOT NULL, tags CLOB NOT NULL, schema CLOB NOT NULL, description CLOB DEFAULT NULL, server_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_F54D7C4B1844E6B7 FOREIGN KEY (server_id) REFERENCES mcp_server (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_tooldef_server ON tool_def (server_id)');
        $this->addSql('INSERT INTO tool_def (id, name, tags, schema, description, server_id) SELECT id, name, tags, schema, description, server_id FROM __temp__tool_def');
        $this->addSql('DROP TABLE __temp__tool_def');
    }
}
