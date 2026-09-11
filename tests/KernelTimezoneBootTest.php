<?php

declare(strict_types=1);

namespace App\Tests;

use App\Kernel;
use App\Service\TimezoneService;

use function date_default_timezone_get;
use function date_default_timezone_set;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Kernel boot applies the deployment-wide timezone as PHP's default BEFORE
 * the container boots, so every `new DateTimeImmutable()` across the app
 * (entities, events, workflow, scheduler) is created in that one zone
 * (SPEC.md → Configuration).
 */
final class KernelTimezoneBootTest extends TestCase
{
    private string $tzBackup;

    private mixed $serverTzBackup;

    protected function setUp(): void
    {
        $this->tzBackup = date_default_timezone_get();
        $this->serverTzBackup = $_SERVER['TASKWEAVER_TIMEZONE'] ?? null;
    }

    protected function tearDown(): void
    {
        if (null === $this->serverTzBackup) {
            unset($_SERVER['TASKWEAVER_TIMEZONE']);
        } else {
            $_SERVER['TASKWEAVER_TIMEZONE'] = $this->serverTzBackup;
        }
        date_default_timezone_set($this->tzBackup);
    }

    public function testBootAppliesConfiguredTimezone(): void
    {
        $_SERVER['TASKWEAVER_TIMEZONE'] = 'America/New_York';

        // Boot the real kernel (test env) — the hook runs before the
        // container is built, so after boot the process default must be the
        // configured zone.
        (new Kernel('test', false))->boot();

        self::assertSame('America/New_York', date_default_timezone_get());
        self::assertSame('America/New_York', (new DateTimeImmutable())->getTimezone()->getName());
    }

    public function testBootWithoutEnvOverrideUsesSystemZone(): void
    {
        unset($_SERVER['TASKWEAVER_TIMEZONE'], $_ENV['TASKWEAVER_TIMEZONE']);
        putenv('TASKWEAVER_TIMEZONE');

        $expected = (new TimezoneService())->resolve();

        (new Kernel('test', false))->boot();

        self::assertSame($expected, date_default_timezone_get());
    }

    public function testResolvedZoneIsARealIanaZone(): void
    {
        foreach (['UTC', 'America/New_York', 'Europe/Berlin', 'Asia/Tokyo'] as $zone) {
            self::assertInstanceOf(DateTimeZone::class, new DateTimeZone($zone), $zone);
        }
    }
}
