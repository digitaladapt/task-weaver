<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Conversation history compaction.
 *
 * A conversation compacts its own transcript into a rolling summary (one
 * column max — each successful compaction replaces the previous summary).
 *
 * - conversation.summary                    : the rolling compacted transcript
 *                                             (null until the first compaction)
 * - conversation.summary_through_message_id : high-water mark — every message
 *                                             up to and including this message
 *                                             is covered by the summary; only
 *                                             newer messages are rendered raw.
 * - task.compaction_conversation_id         : nullable UNIQUE → a transient
 *                                             compaction run for that
 *                                             conversation (at most one live
 *                                             per conversation; hard-deleted
 *                                             after the summary is stored —
 *                                             same lifecycle as a reply run).
 * - task.compaction_cutoff_message_id       : the last message folded into the
 *                                             run's prompt (its intended
 *                                             high-water mark, recorded at
 *                                             creation — deterministic).
 *
 * The UNIQUE index doubles as the cheap, atomic dedup guard: a second
 * compaction for a conversation already being compacted is quietly dropped.
 *
 * NOTE: UUID columns are BLOB (16-byte binary) — the same representation the
 * existing task/step/event/conversation tables use with Doctrine's `uuid` type.
 */
final class Version20260913120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add conversation.summary / summary_through_message_id and task.compaction_conversation_id (unique) for history compaction.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversation ADD COLUMN summary CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE conversation ADD COLUMN summary_through_message_id BLOB DEFAULT NULL');

        $this->addSql('ALTER TABLE task ADD COLUMN compaction_conversation_id BLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD COLUMN compaction_cutoff_message_id BLOB DEFAULT NULL');
        // UNIQUE ⇒ at most one live compaction task per conversation.
        $this->addSql('CREATE UNIQUE INDEX idx_task_compaction_conversation ON task (compaction_conversation_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_task_compaction_conversation');
        $this->addSql('ALTER TABLE task DROP COLUMN compaction_cutoff_message_id');
        $this->addSql('ALTER TABLE task DROP COLUMN compaction_conversation_id');
        $this->addSql('ALTER TABLE conversation DROP COLUMN summary_through_message_id');
        $this->addSql('ALTER TABLE conversation DROP COLUMN summary');
    }
}
