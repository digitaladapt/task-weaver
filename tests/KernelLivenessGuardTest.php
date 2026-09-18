<?php

declare(strict_types=1);

namespace App\Tests;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * The liveness-ladder boot guard (docs/step-liveness-plan.md §3.8).
 *
 * The two clocks only make sense as a ladder: grace < idle_timeout <
 * step_timeout. A misconfigured pair silently disables a clock (or gives the
 * worker a non-positive idle window), so the app refuses to boot rather than
 * degrading quietly. This is a logic error, not a secret — checked in every
 * environment, unlike the prod-only secret guard.
 */
final class KernelLivenessGuardTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    /**
     * Run the guard in isolation: a real boot would build the container.
     */
    private function bootGuardOnly(): void
    {
        $kernel = new class('dev', false) extends Kernel {
            public function boot(): void
            {
                // Mirrors Kernel::boot()'s guard call without the container.
                $this->assertLivenessTimeoutsAreCoherentForTest();
                parent::boot();
            }

            public function assertLivenessTimeoutsAreCoherentForTest(): void
            {
                $method = new ReflectionMethod(Kernel::class, 'assertLivenessTimeoutsAreCoherent');
                $method->setAccessible(true);
                $method->invoke($this);
            }
        };

        $kernel->boot();
    }

    public function testGraceLargerThanIdleIsRejected(): void
    {
        $_SERVER['TASKWEAVER_STEP_TIMEOUT'] = '600';
        $_SERVER['TASKWEAVER_STEP_IDLE_TIMEOUT'] = '10';
        $_SERVER['TASKWEAVER_STEP_GRACE'] = '15';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TASKWEAVER_STEP_GRACE');

        $this->bootGuardOnly();
    }

    public function testGraceEqualToIdleIsRejected(): void
    {
        // Equal is also fatal: the worker's idle window would be zero, so it
        // would abort every step the instant it started.
        $_SERVER['TASKWEAVER_STEP_TIMEOUT'] = '600';
        $_SERVER['TASKWEAVER_STEP_IDLE_TIMEOUT'] = '15';
        $_SERVER['TASKWEAVER_STEP_GRACE'] = '15';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TASKWEAVER_STEP_GRACE');

        $this->bootGuardOnly();
    }

    public function testIdleLargerThanStepTimeoutIsRejected(): void
    {
        $_SERVER['TASKWEAVER_STEP_TIMEOUT'] = '300';
        $_SERVER['TASKWEAVER_STEP_IDLE_TIMEOUT'] = '600';
        $_SERVER['TASKWEAVER_STEP_GRACE'] = '15';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TASKWEAVER_STEP_IDLE_TIMEOUT');

        $this->bootGuardOnly();
    }

    public function testNegativeValuesAreRejected(): void
    {
        $_SERVER['TASKWEAVER_STEP_TIMEOUT'] = '-1';
        $_SERVER['TASKWEAVER_STEP_IDLE_TIMEOUT'] = '120';
        $_SERVER['TASKWEAVER_STEP_GRACE'] = '15';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be positive');

        $this->bootGuardOnly();
    }

    public function testCoherentLadderIsAccepted(): void
    {
        $_SERVER['TASKWEAVER_STEP_TIMEOUT'] = '600';
        $_SERVER['TASKWEAVER_STEP_IDLE_TIMEOUT'] = '120';
        $_SERVER['TASKWEAVER_STEP_GRACE'] = '15';

        // If the guard misfired here, this would throw.
        try {
            $this->bootGuardOnly();
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString('refused to boot', $e->getMessage());
        }

        self::assertTrue(true);
    }

    public function testDefaultsAreCoherentWhenUnset(): void
    {
        unset($_SERVER['TASKWEAVER_STEP_TIMEOUT'], $_SERVER['TASKWEAVER_STEP_IDLE_TIMEOUT'], $_SERVER['TASKWEAVER_STEP_GRACE']);

        // The built-in defaults (600/120/15) must satisfy the ladder, or a
        // deployment that sets nothing would refuse to boot.
        try {
            $this->bootGuardOnly();
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString('refused to boot', $e->getMessage());
        }

        self::assertTrue(true);
    }
}
