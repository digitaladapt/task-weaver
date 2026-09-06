<?php

declare(strict_types=1);

namespace App\Service;

use function date_default_timezone_get;
use function trim;

/**
 * Resolves the effective timezone for scheduling.
 *
 * v1 is single-tenant: there is no per-user timezone setting in the UI.
 * A single deployment-wide timezone is configured via the
 * TASKWEAVER_TIMEZONE env var; when unset we fall back to PHP's default
 * (system) timezone.
 *
 * The task.timezone column is kept for future multi-user support, but for
 * now every task is stamped with the resolved value at creation time so the
 * scheduler stays timezone-aware without a per-task UI control.
 */
final class TimezoneService
{
    public function __construct(
        private readonly string $timezone = '',
    ) {
    }

    /**
     * The effective timezone: env override if set, otherwise the system
     * default timezone.
     */
    public function resolve(): string
    {
        $tz = trim($this->timezone);

        return '' !== $tz ? $tz : date_default_timezone_get();
    }
}
