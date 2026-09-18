<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Second step clock: idle deadline (docs/step-liveness-plan.md §4).
 *
 * `expires_at` is the END-TO-END budget — stamped once when the step goes
 * `running`, never refreshed. `idle_expires_at` is the ROLLING budget —
 * refreshed by every sign of activity (event registration, tool calls,
 * streamed-output progress beats). When it passes, the step has gone silent
 * for longer than TASKWEAVER_STEP_IDLE_TIMEOUT and is reaped as a worker
 * stall, distinct from the absolute deadline.
 *
 * Nullable column only — online-safe. Existing rows keep NULL, and a NULL
 * idle deadline is treated as "never idle-stale", so a step that was already
 * running during the deploy cannot be reaped by the new clock until it is
 * next stamped (absence of a clock is not a stall).
 */
final class Version20260917120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable step.idle_expires_at for the rolling idle deadline.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE step ADD COLUMN idle_expires_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE step DROP COLUMN idle_expires_at');
    }
}
