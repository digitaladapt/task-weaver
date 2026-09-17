<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-step / per-message LLM model selection (docs/model-selection-plan.md §3).
 *
 * - step.model    : optional per-step model override. NULL = deployment
 *                   default (TASKWEAVER_LLM_MODEL).
 * - message.model : optional per-message model override for conversation
 *                   replies; propagated into the throwaway reply run's step.
 *                   Assistant messages record the model that actually ran
 *                   (provenance). NULL = default.
 *
 * Nullable columns only — online-safe; existing rows keep NULL (default).
 */
final class Version20260916100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable step.model and message.model for LLM model selection.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE step ADD COLUMN model VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD COLUMN model VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE step DROP COLUMN model');
        $this->addSql('ALTER TABLE message DROP COLUMN model');
    }
}
