<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Conversations & follow-ups (docs/conversations-plan.md).
 *
 * - conversation          : standalone chat thread (messages only; no task)
 * - message               : user/assistant turn; end result only
 * - message_tool_log      : tool executions that produced an assistant reply
 * - event.run_id          : groups one step execution (audit)
 * - step.run_id           : minted when the step is marked running
 * - task.conversation_id  : nullable UNIQUE → a transient reply run, at most
 *                           one live reply task per conversation; hard-deleted
 *                           after the reply is logged (no residue)
 *
 * NOTE: UUID columns are BLOB (16-byte binary) — the same representation the
 * existing task/step/event tables use with Doctrine's `uuid` type.
 */
final class Version20260911130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add conversation, message, message_tool_log tables and run_id / conversation_id columns.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE conversation (
                id BLOB NOT NULL
                    PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                source_run_id VARCHAR(36) DEFAULT NULL,
                source_event_id BLOB DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                archived_at DATETIME DEFAULT NULL
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE message (
                id BLOB NOT NULL
                    PRIMARY KEY,
                conversation_id BLOB NOT NULL,
                role VARCHAR(16) NOT NULL,
                content CLOB NOT NULL,
                tags CLOB NOT NULL,
                status VARCHAR(16) NOT NULL,
                error CLOB DEFAULT NULL,
                is_seed BOOLEAN NOT NULL,
                source_event_id BLOB DEFAULT NULL,
                reply_task_id BLOB DEFAULT NULL,
                created_at DATETIME NOT NULL,
                completed_at DATETIME DEFAULT NULL,
                CONSTRAINT FK_MSG_CONVERSATION FOREIGN KEY (conversation_id)
                    REFERENCES conversation (id) ON DELETE CASCADE
                    NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_message_conversation ON message (conversation_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE message_tool_log (
                id BLOB NOT NULL
                    PRIMARY KEY,
                message_id BLOB NOT NULL,
                tool_name VARCHAR(255) NOT NULL,
                kind VARCHAR(16) NOT NULL,
                arguments CLOB NOT NULL,
                result CLOB DEFAULT NULL,
                error CLOB DEFAULT NULL,
                status VARCHAR(16) NOT NULL,
                created_at DATETIME NOT NULL,
                CONSTRAINT FK_MTL_MESSAGE FOREIGN KEY (message_id)
                    REFERENCES message (id) ON DELETE CASCADE
                    NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_mtl_message ON message_tool_log (message_id)');

        // run_id: groups one step execution (minted at markStepRunning, copied
        // onto every event of that run). Indexed for tool-log materialization.
        $this->addSql('ALTER TABLE event ADD COLUMN run_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_event_run ON event (run_id)');
        $this->addSql('ALTER TABLE step ADD COLUMN run_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_step_run ON step (run_id)');

        // conversation_id: NULL = normal task; non-NULL = transient reply run.
        // UNIQUE ⇒ at most one live reply task per conversation.
        $this->addSql(<<<'SQL'
            ALTER TABLE task ADD COLUMN conversation_id BLOB DEFAULT NULL
                REFERENCES conversation (id) ON DELETE SET NULL
        SQL);
        $this->addSql('CREATE UNIQUE INDEX idx_task_conversation ON task (conversation_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE message_tool_log');
        $this->addSql('DROP TABLE message');

        // task.conversation_id: SQLite requires a table rebuild to drop a
        // column; remove the FK reference by recreating task without it.
        $this->addSql('CREATE TEMPORARY TABLE __temp__task AS SELECT id, name, description, schedule, timezone, status, priority, created_at, updated_at, next_run_at, deleted_at FROM task');
        $this->addSql('DROP TABLE task');
        $this->addSql('CREATE TABLE task (id BLOB NOT NULL, name VARCHAR(255) NOT NULL, description CLOB NOT NULL, schedule VARCHAR(255) DEFAULT NULL, timezone VARCHAR(64) DEFAULT \'UTC\' NOT NULL, status VARCHAR(16) DEFAULT \'draft\' NOT NULL, priority INTEGER DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, next_run_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO task (id, name, description, schedule, timezone, status, priority, created_at, updated_at, next_run_at, deleted_at) SELECT id, name, description, schedule, timezone, status, priority, created_at, updated_at, next_run_at, deleted_at FROM __temp__task');
        $this->addSql('DROP TABLE __temp__task');

        // SQLite cannot drop an ALTER-added column via DROP COLUMN before
        // 3.35; rebuild event and step without run_id as well.
        $this->addSql('CREATE TEMPORARY TABLE __temp__event AS SELECT id, api_key, type, payload, timestamp, step_id, task_id, worker_id FROM event');
        $this->addSql('DROP TABLE event');
        $this->addSql('CREATE TABLE event (id BLOB NOT NULL, api_key VARCHAR(255) DEFAULT NULL, type VARCHAR(32) NOT NULL, payload CLOB NOT NULL, timestamp DATETIME NOT NULL, step_id BLOB NOT NULL, task_id BLOB NOT NULL, worker_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_3BAE0AA773B21E9C FOREIGN KEY (step_id) REFERENCES step (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3BAE0AA78DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3BAE0AA76B20BA36 FOREIGN KEY (worker_id) REFERENCES worker (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_event_step ON event (step_id)');
        $this->addSql('CREATE INDEX idx_event_task ON event (task_id)');
        $this->addSql('CREATE INDEX idx_event_worker ON event (worker_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__step AS SELECT id, name, description, tags, is_final, sort_order, status, started_at, finished_at, expires_at, result, task_id FROM step');
        $this->addSql('DROP TABLE step');
        $this->addSql('CREATE TABLE step (id BLOB NOT NULL, name VARCHAR(255) NOT NULL, description CLOB NOT NULL, tags CLOB NOT NULL, is_final BOOLEAN NOT NULL, sort_order INTEGER DEFAULT 0 NOT NULL, status VARCHAR(16) DEFAULT \'pending\' NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, result CLOB DEFAULT NULL, task_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_43B9FE3C8DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_43B9FE3C8DB60186 ON step (task_id)');
        $this->addSql('INSERT INTO event (id, api_key, type, payload, timestamp, step_id, task_id, worker_id) SELECT id, api_key, type, payload, timestamp, step_id, task_id, worker_id FROM __temp__event');
        $this->addSql('DROP TABLE __temp__event');
        $this->addSql('INSERT INTO step (id, name, description, tags, is_final, sort_order, status, started_at, finished_at, expires_at, result, task_id) SELECT id, name, description, tags, is_final, sort_order, status, started_at, finished_at, expires_at, result, task_id FROM __temp__step');
        $this->addSql('DROP TABLE __temp__step');

        $this->addSql('DROP TABLE conversation');
    }
}
