<?php

declare(strict_types=1);

namespace App\Service;

use function count;
use function sprintf;

/**
 * Maps between a structured schedule selection (the admin UI's schedule picker)
 * and a raw cron expression.
 *
 * The UI never shows a raw cron string for the supported shapes (SPEC.md →
 * Admin UI → Schedule picker). Supported shapes:
 *
 *   minutes  →  «/N * * * *        (every N minutes, 1-60)
 *   hours    →  M «/N * * *        (every N hours at minute M, 1-24)
 *   days     →  0 H * * DOW        (everyday | weekends(0,6) | weekdays(1-5))
 *   weeks    →  0 H * * N          (a specific weekday N, 0=Sunday)
 *   months   →  0 H D * *          (a specific day-of-month 1-28)
 *
 * Anything that doesn't match one of these shapes is treated as `custom` —
 * the raw cron string is shown and edited as-is, so no existing schedule is
 * ever silently mangled or lost.
 *
 * Time-of-day buckets map to fixed hours:
 *   morning 08:00 · afternoon 13:00 · evening 18:00 · night 22:00
 */
final class ScheduleCronService
{
    public const TYPE_ONE_OFF = 'one-off';
    public const TYPE_MINUTES = 'minutes';
    public const TYPE_HOURS = 'hours';
    public const TYPE_DAYS = 'days';
    public const TYPE_WEEKS = 'weeks';
    public const TYPE_MONTHS = 'months';
    public const TYPE_CUSTOM = 'custom';

    public const TIME_MORNING = 'morning';
    public const TIME_AFTERNOON = 'afternoon';
    public const TIME_EVENING = 'evening';
    public const TIME_NIGHT = 'night';

    public const DAYS_EVERYDAY = 'everyday';
    public const DAYS_WEEKENDS = 'weekends';
    public const DAYS_WEEKDAYS = 'weekdays';

    private const TIME_HOURS = [
        self::TIME_MORNING => 8,
        self::TIME_AFTERNOON => 13,
        self::TIME_EVENING => 18,
        self::TIME_NIGHT => 22,
    ];

    private const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    /**
     * @return array<string, mixed> the structured schedule for a task
     */
    public function describe(?string $cron): array
    {
        if (null === $cron || '' === $cron) {
            return ['type' => self::TYPE_ONE_OFF, 'cron' => ''];
        }

        $parts = preg_split('/\s+/', trim($cron));
        if (false === $parts || 5 !== count($parts)) {
            return ['type' => self::TYPE_CUSTOM, 'cron' => $cron];
        }

        [$minute, $hour, $dom, $month, $dow] = $parts;

        // minutes: */N * * * *
        if (preg_match('#^\*/?([0-9]{1,2})$#', $minute) && '*' === $hour && '*' === $dom && '*' === $month && '*' === $dow) {
            $n = (int) ltrim($minute, '*/');
            if ($n >= 1 && $n <= 60) {
                return ['type' => self::TYPE_MINUTES, 'number' => $n, 'cron' => $cron];
            }
        }

        // hours: M */N * * *
        if (preg_match('#^\*/?([0-9]{1,2})$#', $hour) && '*' === $dom && '*' === $month && '*' === $dow && preg_match('#^[0-9]{1,2}$#', $minute)) {
            $n = (int) ltrim($hour, '*/');
            if ($n >= 1 && $n <= 24) {
                return ['type' => self::TYPE_HOURS, 'number' => $n, 'minute' => (int) $minute, 'cron' => $cron];
            }
        }

        // days: 0 H * * DOW
        if ('0' === $minute && '*' === $dom && '*' === $month && is_numeric($hour) && $this->isDayOfWeekPattern($dow)) {
            return [
                'type' => self::TYPE_DAYS,
                'days' => $this->dowToDays($dow),
                'time' => $this->hourToTime((int) $hour),
                'cron' => $cron,
            ];
        }

        // weeks: 0 H * * N  (single numeric weekday)
        if ('0' === $minute && '*' === $dom && '*' === $month && is_numeric($hour) && $this->isSingleWeekday($dow)) {
            return [
                'type' => self::TYPE_WEEKS,
                'day' => self::WEEKDAYS[(int) $dow],
                'time' => $this->hourToTime((int) $hour),
                'cron' => $cron,
            ];
        }

        // months: 0 H D * *
        if ('0' === $minute && '*' === $month && '*' === $dow && is_numeric($hour) && is_numeric($dom) && (int) $dom >= 1 && (int) $dom <= 28) {
            return [
                'type' => self::TYPE_MONTHS,
                'day' => (int) $dom,
                'time' => $this->hourToTime((int) $hour),
                'cron' => $cron,
            ];
        }

        return ['type' => self::TYPE_CUSTOM, 'cron' => $cron];
    }

    /**
     * Build a cron string from the submitted structured schedule.
     *
     * @param array<string, mixed> $data
     */
    public function build(array $data): ?string
    {
        $type = (string) ($data['schedule_type'] ?? self::TYPE_ONE_OFF);

        if (self::TYPE_ONE_OFF === $type) {
            return null;
        }

        if (self::TYPE_CUSTOM === $type) {
            $cron = trim((string) ($data['schedule_cron'] ?? ''));

            return '' === $cron ? null : $cron;
        }

        if (self::TYPE_MINUTES === $type) {
            $n = max(1, min(60, (int) ($data['schedule_number'] ?? 5)));

            return sprintf('*/%d * * * *', $n);
        }

        if (self::TYPE_HOURS === $type) {
            $n = max(1, min(24, (int) ($data['schedule_number'] ?? 1)));
            $minute = max(0, min(59, (int) ($data['schedule_minute'] ?? 0)));

            return sprintf('%d */%d * * *', $minute, $n);
        }

        if (self::TYPE_DAYS === $type) {
            $hour = self::TIME_HOURS[(string) ($data['schedule_time'] ?? self::TIME_MORNING)] ?? 8;
            $dow = $this->daysToDow((string) ($data['schedule_days'] ?? self::DAYS_EVERYDAY));

            return sprintf('0 %d * * %s', $hour, $dow);
        }

        if (self::TYPE_WEEKS === $type) {
            $hour = self::TIME_HOURS[(string) ($data['schedule_time'] ?? self::TIME_MORNING)] ?? 8;
            $dayIndex = $this->weekdayToIndex((string) ($data['schedule_day'] ?? 'Monday'));

            return sprintf('0 %d * * %d', $hour, $dayIndex);
        }

        if (self::TYPE_MONTHS === $type) {
            $hour = self::TIME_HOURS[(string) ($data['schedule_time'] ?? self::TIME_MORNING)] ?? 8;
            $dom = max(1, min(28, (int) ($data['schedule_day'] ?? 1)));

            return sprintf('0 %d %d * *', $hour, $dom);
        }

        return null;
    }

    private function isDayOfWeekPattern(string $dow): bool
    {
        return $dow === $this->daysToDow(self::DAYS_EVERYDAY)
            || $dow === $this->daysToDow(self::DAYS_WEEKENDS)
            || $dow === $this->daysToDow(self::DAYS_WEEKDAYS);
    }

    private function isSingleWeekday(string $dow): bool
    {
        return ctype_digit($dow) && (int) $dow >= 0 && (int) $dow <= 6;
    }

    private function dowToDays(string $dow): string
    {
        return match ($dow) {
            '1-5' => self::DAYS_WEEKDAYS,
            '0,6' => self::DAYS_WEEKENDS,
            default => self::DAYS_EVERYDAY,
        };
    }

    private function daysToDow(string $days): string
    {
        return match ($days) {
            self::DAYS_WEEKDAYS => '1-5',
            self::DAYS_WEEKENDS => '0,6',
            default => '*',
        };
    }

    private function hourToTime(int $hour): string
    {
        foreach (self::TIME_HOURS as $time => $h) {
            if ($h === $hour) {
                return $time;
            }
        }

        // Not one of our canonical buckets — fall back to morning-friendly default.
        return self::TIME_MORNING;
    }

    private function weekdayToIndex(string $day): int
    {
        $i = array_search($day, self::WEEKDAYS, true);

        return false === $i ? 1 : $i;
    }
}
