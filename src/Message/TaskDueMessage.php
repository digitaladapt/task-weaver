<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched when a recurring task's cron expression says it's due.
 * The handler marks the task `ready` (enqueue for claim) and recalculates
 * next_run_at. See SPEC.md → Scheduling.
 */
final class TaskDueMessage
{
    public function __construct(
        public readonly string $taskId,
    ) {
    }
}
