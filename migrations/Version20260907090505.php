<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Admin API key table for the web UI (single-user, penny-track pattern).
 */
final class Version20260907090505 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create api_key table for admin authentication.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE api_key (
                id CHAR(36) NOT NULL -- (doctrine type: uuid)
                    PRIMARY KEY,
                key_hash VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL,
                last_used_at DATETIME DEFAULT NULL
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX idx_api_key_key_hash ON api_key (key_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE api_key');
    }
}
