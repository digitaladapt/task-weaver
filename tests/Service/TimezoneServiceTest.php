<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TimezoneService;

use function date_default_timezone_get;
use function date_default_timezone_set;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

use function is_string;

use PHPUnit\Framework\TestCase;

/**
 * The deployment-wide timezone is the single source of truth for every
 * timestamp in TaskWeaver: env override, else OS/system detection, and it is
 * applied as PHP's default at kernel boot so bare `new DateTimeImmutable()`
 * calls inherit it (SPEC.md → Configuration).
 */
final class TimezoneServiceTest extends TestCase
{
    private string $tzBackup;

    private string $envBackup;

    protected function setUp(): void
    {
        $this->tzBackup = date_default_timezone_get();
        $this->envBackup = (string) (getenv('TASKWEAVER_TIMEZONE') ?: '');
    }

    protected function tearDown(): void
    {
        $this->setEnv('TASKWEAVER_TIMEZONE', $this->envBackup);
        date_default_timezone_set($this->tzBackup);
    }

    public function testConfiguredZoneIsUsed(): void
    {
        $service = new TimezoneService('America/New_York');

        self::assertSame('America/New_York', $service->resolve());
    }

    public function testBlankConfiguredZoneFallsBackToSystem(): void
    {
        $service = new TimezoneService('');

        self::assertSame($this->systemDefault(), $service->resolve());
    }

    public function testInvalidConfiguredZoneFallsBackToSystem(): void
    {
        // A typo must not crash the app — it falls back to the system zone.
        $service = new TimezoneService('Not/A_Real_Zone');

        self::assertSame($this->systemDefault(), $service->resolve());
    }

    public function testZoneReturnsValidDateTimeZone(): void
    {
        $service = new TimezoneService('Europe/Berlin');

        self::assertInstanceOf(DateTimeZone::class, $service->zone());
        self::assertSame('Europe/Berlin', $service->zone()->getName());
    }

    public function testNowIsInTheConfiguredZone(): void
    {
        $service = new TimezoneService('America/New_York');

        $now = $service->now();

        self::assertInstanceOf(DateTimeImmutable::class, $now);
        self::assertSame('America/New_York', $now->getTimezone()->getName());
    }

    public function testApplyDefaultSetsProcessTimezone(): void
    {
        $this->setEnv('TASKWEAVER_TIMEZONE', 'Europe/Berlin');
        $_SERVER['TASKWEAVER_TIMEZONE'] = 'Europe/Berlin';

        TimezoneService::applyDefault();

        self::assertSame('Europe/Berlin', date_default_timezone_get());
    }

    public function testApplyDefaultFallbackIsSystemZone(): void
    {
        $this->setEnv('TASKWEAVER_TIMEZONE', '');
        unset($_SERVER['TASKWEAVER_TIMEZONE'], $_ENV['TASKWEAVER_TIMEZONE']);

        TimezoneService::applyDefault();

        self::assertSame($this->systemDefault(), date_default_timezone_get());
    }

    /**
     * Mirror of TimezoneService::systemDefault() (protected) — compute what
     * the test process would resolve on a clean machine so assertions match
     * the environment the suite runs in.
     */
    private function systemDefault(): string
    {
        // TZ env (IANA) → /etc/timezone → /etc/localtime → PHP default.
        $tz = getenv('TZ');
        if (is_string($tz) && '' !== trim($tz) && $this->isZone($tz)) {
            return trim($tz);
        }

        $etc = @file_get_contents('/etc/timezone');
        if (is_string($etc) && '' !== trim($etc) && $this->isZone(trim($etc))) {
            return trim($etc);
        }

        $local = @readlink('/etc/localtime');
        if (is_string($local) && '' !== $local && str_contains($local, 'zoneinfo/')) {
            $parts = explode('zoneinfo/', $local);
            $zone = trim((string) end($parts));
            if ('' !== $zone && $this->isZone($zone)) {
                return $zone;
            }
        }

        return date_default_timezone_get();
    }

    private function isZone(string $zone): bool
    {
        try {
            new DateTimeZone($zone);

            return true;
        } catch (Exception) {
            return false;
        }
    }

    private function setEnv(string $name, string $value): void
    {
        if ('' === $value) {
            putenv($name);
            unset($_ENV[$name]);

            return;
        }

        putenv($name.'='.$value);
        $_ENV[$name] = $value;
    }
}
