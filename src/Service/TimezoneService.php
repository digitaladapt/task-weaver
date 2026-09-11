<?php

declare(strict_types=1);

namespace App\Service;

use function date_default_timezone_get;
use function date_default_timezone_set;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

use function explode;
use function file_get_contents;
use function getenv;
use function is_string;
use function readlink;
use function str_contains;
use function trim;

/**
 * Resolves the effective timezone for the whole deployment.
 *
 * v1 is single-tenant: there is no per-user timezone setting in the UI.
 * A single deployment-wide timezone is configured via the
 * TASKWEAVER_TIMEZONE env var; when unset (or blank) we fall back to the
 * OS/system timezone.
 *
 * The task.timezone column is kept for future multi-user support, but for
 * now every task is stamped with the resolved value at creation time so the
 * scheduler stays timezone-aware without a per-task UI control.
 *
 * Consistency contract (SPEC.md → Configuration):
 *   - ALL timestamps (created/updated, next run, step start/finish/expiry,
 *     events, tool calls, worker seen times) are produced with
 *     DateTimeImmutable in this timezone and stored/displayed as wall-clock
 *     in it — no UTC conversion anywhere.
 *   - The kernel sets the resolved value as PHP's default timezone at boot,
 *     so bare `new DateTimeImmutable()` calls inherit it too.
 */
final class TimezoneService
{
    private ?string $resolved = null;

    public function __construct(
        private readonly string $timezone = '',
    ) {
    }

    /**
     * Build a service from the current process environment — used by the
     * kernel (which boots before the container exists).
     */
    public static function fromCurrentEnv(): self
    {
        $value = $_SERVER['TASKWEAVER_TIMEZONE']
            ?? $_ENV['TASKWEAVER_TIMEZONE']
            ?? self::env('TASKWEAVER_TIMEZONE')
            ?? '';

        return new self(is_string($value) ? $value : '');
    }

    /**
     * Set PHP's default timezone to the deployment-wide value. Called by the
     * kernel before the container boots so bare `new DateTimeImmutable()`
     * calls throughout the app are created in the configured zone.
     */
    public static function applyDefault(): void
    {
        date_default_timezone_set(self::fromCurrentEnv()->resolve());
    }

    /**
     * The effective timezone: env override if set (and valid), otherwise the
     * system timezone.
     */
    public function resolve(): string
    {
        if (null !== $this->resolved) {
            return $this->resolved;
        }

        $configured = trim($this->timezone);

        if ('' !== $configured) {
            // Trust the operator's value only if it names a real zone; a typo
            // must not take the whole app down — fall back to the system zone.
            if (self::isValidZone($configured)) {
                return $this->resolved = $configured;
            }
        }

        return $this->resolved = $this->systemDefault();
    }

    /**
     * Resolve the OS/system timezone in a best-effort order:
     *   1. the TZ environment variable (containers/ops commonly set it);
     *   2. /etc/timezone content (Debian/Ubuntu convention);
     *   3. the /etc/localtime symlink target (e.g. …/zoneinfo/America/New_York);
     *   4. PHP's own default (correct when date.timezone is unset and the OS
     *      zone is found by PHP itself; may be pinned by php.ini otherwise).
     *
     * Every candidate is validated as a real IANA zone before use.
     */
    private function systemDefault(): string
    {
        // 1. TZ env (IANA name; POSIX forms like "EST5EDT" are skipped).
        $tz = getenv('TZ');
        if (is_string($tz) && '' !== trim($tz) && self::isValidZone(trim($tz))) {
            return trim($tz);
        }

        // 2. /etc/timezone (Debian: "America/New_York").
        $etcTimezone = @file_get_contents('/etc/timezone');
        if (is_string($etcTimezone) && '' !== trim($etcTimezone) && self::isValidZone(trim($etcTimezone))) {
            return trim($etcTimezone);
        }

        // 3. /etc/localtime symlink (…/zoneinfo/<Zone>/<City>).
        $localtime = @readlink('/etc/localtime');
        if (is_string($localtime) && '' !== $localtime && str_contains($localtime, 'zoneinfo/')) {
            $parts = explode('zoneinfo/', $localtime);
            $zone = trim((string) end($parts));
            if ('' !== $zone && self::isValidZone($zone)) {
                return $zone;
            }
        }

        // 4. Whatever PHP already resolved (system default or php.ini pin).
        return date_default_timezone_get();
    }

    /**
     * The effective timezone as a DateTimeZone object.
     */
    public function zone(): DateTimeZone
    {
        return new DateTimeZone($this->resolve());
    }

    /**
     * "Now" in the effective timezone. Use this instead of a bare
     * `new DateTimeImmutable()` when you need to be explicit — though after
     * the kernel sets the default, bare constructions are equivalent.
     */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->zone());
    }

    private static function isValidZone(string $zone): bool
    {
        try {
            new DateTimeZone($zone);

            return true;
        } catch (Exception) {
            return false;
        }
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return false === $value ? null : $value;
    }
}
