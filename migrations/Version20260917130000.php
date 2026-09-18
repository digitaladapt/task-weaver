<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Partial (budget-truncated) step results (docs/step-liveness-plan.md §3.5).
 *
 * When a step runs out of budget mid-generation, the worker stops `grace`
 * seconds early and submits what it has. That result is COMPLETED, not
 * failed — failure semantics persist no result, which would discard exactly
 * the partial output the worker fought to keep — but it must be marked so
 * neither the audit trail nor the final step's envelope pretends it is whole.
 *
 * - step.partial        : true when the result was truncated by a budget
 *                         (idle stall or the absolute deadline).
 * - step.partial_reason : the worker's own words, e.g. "idle timeout: LLM
 *                         silent 105s". NULL unless partial.
 *
 * Both default to "not partial", so existing rows are unaffected.
 */
final class Version20260917130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add step.partial / step.partial_reason for budget-truncated results.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE step ADD COLUMN partial BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE step ADD COLUMN partial_reason VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE step DROP COLUMN partial_reason');
        $this->addSql('ALTER TABLE step DROP COLUMN partial');
    }
}
