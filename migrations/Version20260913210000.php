<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Conversation soft delete — parity with the task soft delete.
 *
 * - conversation.deleted_at : soft-delete marker. When set, the conversation
 *   is hidden from the admin UI and excluded from the reply-run safety net,
 *   but it and its messages / tool logs are kept for history preservation
 *   (same policy as task.deleted_at — SPEC.md → Soft Delete).
 */
final class Version20260913210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add conversation.deleted_at for conversation soft delete.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversation ADD COLUMN deleted_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversation DROP COLUMN deleted_at');
    }
}
