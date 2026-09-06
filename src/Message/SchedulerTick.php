<?php

declare(strict_types=1);

namespace App\Message;

use DateTimeImmutable;

/**
 * Periodic scheduler tick — dispatched by a cron-driven consumer (or a
 * Messenger Scheduler) to check for due tasks. See SPEC.md → Scheduling.
 */
final class SchedulerTick
{
    public function __construct(
        public readonly DateTimeImmutable $at,
    ) {
    }
}
