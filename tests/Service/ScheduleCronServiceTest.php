<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ScheduleCronService;
use PHPUnit\Framework\TestCase;

class ScheduleCronServiceTest extends TestCase
{
    private ScheduleCronService $service;

    protected function setUp(): void
    {
        $this->service = new ScheduleCronService();
    }

    public function testOneOffReturnsNull(): void
    {
        self::assertNull($this->service->build(['schedule_type' => 'one-off']));
    }

    public function testMinutesBuildsStepExpression(): void
    {
        $cron = $this->service->build([
            'schedule_type' => 'minutes',
            'schedule_number' => '5',
        ]);

        self::assertSame('*/5 * * * *', $cron);
    }

    public function testMinutesClampsOutOfRangeValues(): void
    {
        // A malicious/curled request can send any value; the server must clamp.
        self::assertSame('*/60 * * * *', $this->service->build([
            'schedule_type' => 'minutes',
            'schedule_number' => '999',
        ]));
        self::assertSame('*/1 * * * *', $this->service->build([
            'schedule_type' => 'minutes',
            'schedule_number' => '0',
        ]));
        self::assertSame('*/1 * * * *', $this->service->build([
            'schedule_type' => 'minutes',
            'schedule_number' => '-5',
        ]));
    }

    public function testHoursBuildsWithMinute(): void
    {
        $cron = $this->service->build([
            'schedule_type' => 'hours',
            'schedule_number' => '2',
            'schedule_minute' => '30',
        ]);

        self::assertSame('30 */2 * * *', $cron);
    }

    public function testHoursClampsOutOfRangeValues(): void
    {
        self::assertSame('59 */24 * * *', $this->service->build([
            'schedule_type' => 'hours',
            'schedule_number' => '999',
            'schedule_minute' => '99',
        ]));
        self::assertSame('0 */1 * * *', $this->service->build([
            'schedule_type' => 'hours',
            'schedule_number' => '0',
            'schedule_minute' => '-1',
        ]));
    }

    public function testDaysThroughMonthsBuildCorrectCron(): void
    {
        self::assertSame('0 8 * * 1-5', $this->service->build([
            'schedule_type' => 'days',
            'schedule_days' => 'weekdays',
            'schedule_time' => 'morning',
        ]));
        self::assertSame('0 8 * * 1', $this->service->build([
            'schedule_type' => 'weeks',
            'schedule_day' => 'Monday',
            'schedule_time' => 'morning',
        ]));
        self::assertSame('0 8 15 * *', $this->service->build([
            'schedule_type' => 'months',
            'schedule_day' => '15',
            'schedule_time' => 'morning',
        ]));
    }

    public function testCustomReturnsRawCron(): void
    {
        self::assertSame('30 6 * * 1', $this->service->build([
            'schedule_type' => 'custom',
            'schedule_cron' => '30 6 * * 1',
        ]));
    }

    public function testDescribeRoundTripsSupportedShapes(): void
    {
        $described = $this->service->describe('*/10 * * * *');
        self::assertSame('minutes', $described['type']);
        self::assertSame(10, $described['number']);

        $described = $this->service->describe('15 */4 * * *');
        self::assertSame('hours', $described['type']);
        self::assertSame(4, $described['number']);
        self::assertSame(15, $described['minute']);

        $described = $this->service->describe('0 8 * * 1-5');
        self::assertSame('days', $described['type']);
        self::assertSame('weekdays', $described['days']);

        $described = $this->service->describe('0 8 15 * *');
        self::assertSame('months', $described['type']);
        self::assertSame(15, $described['day']);
    }

    public function testDescribeFallsBackToCustom(): void
    {
        // An unusual shape must not be mangled — shown/edited as-is.
        $described = $this->service->describe('0 0 1,15 * 1');
        self::assertSame('custom', $described['type']);
        self::assertSame('0 0 1,15 * 1', $described['cron']);
    }
}
