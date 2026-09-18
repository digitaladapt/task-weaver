<?php

declare(strict_types=1);

namespace TaskWeaverWorker\Tests;

use PHPUnit\Framework\TestCase;
use TaskWeaverWorker\StepBudget;

/**
 * The worker's local copy of the step's two clocks
 * (docs/step-liveness-plan.md §3.3).
 *
 * All time is injected, so expiry is asserted without sleeping. The contract
 * that matters: durations come from the controller, both windows are trimmed
 * by `grace`, activity re-arms the idle clock only, and the e2e clock is
 * never extended no matter how busy the step is.
 */
final class StepBudgetTest extends TestCase
{
    public function testArmsBothClocksFromTheBudgetBlock(): void
    {
        $budget = StepBudget::fromStatusResponse([
            'budget' => ['e2e_remaining' => 597, 'idle_timeout' => 120, 'grace' => 15],
        ], [], 1000.0);

        // Windows have grace removed: the worker stops early so it can still
        // transmit before the controller's deadline lands.
        self::assertSame(105.0, $budget->idleWindowSeconds());
        self::assertSame(582.0, $budget->e2eRemainingSeconds(1000.0));
        self::assertSame(15.0, $budget->grace());
        self::assertSame(120.0, $budget->idleTimeout());
        self::assertTrue($budget->isEnabled());
    }

    public function testIdleClockTripsAfterSilence(): void
    {
        $budget = StepBudget::fromStatusResponse([
            'budget' => ['e2e_remaining' => 600, 'idle_timeout' => 120, 'grace' => 20],
        ], [], 0.0);

        // 100s idle window (120 − 20).
        self::assertFalse($budget->idleExceeded(99.0));
        self::assertTrue($budget->idleExceeded(100.0));
        self::assertFalse($budget->e2eExceeded(100.0), 'idle tripping must not imply e2e');
    }

    public function testActivityReArmsIdleButNeverE2e(): void
    {
        $budget = StepBudget::fromStatusResponse([
            'budget' => ['e2e_remaining' => 600, 'idle_timeout' => 120, 'grace' => 20],
        ], [], 0.0);

        $e2eBefore = $budget->e2eRemainingSeconds(0.0);

        // Idle is about to trip; activity saves it.
        self::assertTrue($budget->idleExceeded(150.0));

        $budget->touch(150.0);

        self::assertFalse($budget->idleExceeded(150.0), 'touch must re-arm the idle clock');
        self::assertFalse($budget->idleExceeded(249.0));

        // The absolute budget is unmoved by any amount of activity.
        self::assertSame($e2eBefore, $budget->e2eRemainingSeconds(0.0));
    }

    public function testE2eClockTripsIndependentlyOfActivity(): void
    {
        $budget = StepBudget::fromStatusResponse([
            'budget' => ['e2e_remaining' => 100, 'idle_timeout' => 120, 'grace' => 10],
        ], [], 0.0);

        // 90s e2e window after grace; keep the idle clock busy the whole time.
        for ($t = 0.0; $t <= 100.0; $t += 10.0) {
            $budget->touch($t);
        }

        self::assertFalse($budget->idleExceeded(100.0), 'activity kept the idle clock fresh');
        self::assertTrue($budget->e2eExceeded(100.0), 'the absolute deadline still applies');
        self::assertTrue($budget->shouldStop(100.0));
    }

    public function testReasonNamesTheClockThatTripped(): void
    {
        $idle = StepBudget::fromStatusResponse([
            'budget' => ['e2e_remaining' => 600, 'idle_timeout' => 120, 'grace' => 20],
        ], [], 0.0);
        self::assertStringContainsString('idle timeout', $idle->reason(500.0));

        $e2e = StepBudget::fromStatusResponse([
            'budget' => ['e2e_remaining' => 100, 'idle_timeout' => 300, 'grace' => 10],
        ], [], 0.0);
        $e2e->touch(200.0); // idle kept fresh
        self::assertStringContainsString('deadline', $e2e->reason(200.0));
    }

    public function testFallsBackToProvisionConfigForAnOldController(): void
    {
        // No budget block (older controller) → use provision-issued values.
        $budget = StepBudget::fromStatusResponse([], [
            'step_timeout' => 600,
            'step_idle_timeout' => 120,
            'step_grace' => 15,
        ], 0.0);

        self::assertTrue($budget->isEnabled());
        self::assertSame(105.0, $budget->idleWindowSeconds());
        self::assertSame(585.0, $budget->e2eRemainingSeconds(0.0));
    }

    public function testFallsBackToBuiltInDefaultsWhenNothingIsIssued(): void
    {
        $budget = StepBudget::fromStatusResponse([], [], 0.0);

        self::assertTrue($budget->isEnabled());
        self::assertSame(120.0 - 15.0, $budget->idleWindowSeconds());
        self::assertSame(600.0 - 15.0, $budget->e2eRemainingSeconds(0.0));
    }

    public function testDisabledBudgetNeverTrips(): void
    {
        $budget = StepBudget::disabled(0.0);

        self::assertFalse($budget->isEnabled());
        self::assertFalse($budget->shouldStop(1_000_000.0));
        self::assertFalse($budget->idleExceeded(1_000_000.0));
        self::assertFalse($budget->e2eExceeded(1_000_000.0));
    }

    public function testIdleWindowNeverBecomesNonPositive(): void
    {
        // A misconfigured grace ≥ idle would otherwise make the worker abort
        // instantly on every step. The controller refuses to boot on this pair,
        // but the worker must stay safe against an older/hand-set controller.
        $budget = StepBudget::fromStatusResponse([
            'budget' => ['e2e_remaining' => 600, 'idle_timeout' => 10, 'grace' => 30],
        ], [], 0.0);

        self::assertGreaterThan(0.0, $budget->idleWindowSeconds());
        self::assertFalse($budget->idleExceeded(0.0), 'must not trip at t=0');
    }
}
