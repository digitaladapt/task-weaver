<?php

declare(strict_types=1);

namespace TaskWeaverWorker;

use function is_array;
use function is_numeric;
use function max;
use function microtime;
use function sprintf;

/**
 * The worker's copy of a step's two clocks (docs/step-liveness-plan.md §3.3).
 *
 * The controller is authoritative; this is the worker's *cooperative* copy so
 * a slow-but-alive step degrades gracefully instead of being cut off by a 401
 * with nothing to show for it. Both deadlines come from the controller's
 * `budget` block (see ControllerClient::markRunning), which reports
 * DURATIONS rather than timestamps — worker and controller are separate
 * containers with independent wall clocks, so "you have N seconds left"
 * survives skew where an absolute deadline would not.
 *
 * Two clocks, exactly as on the controller:
 *
 *   e2e   — absolute. Armed once when the step starts running; never re-armed.
 *           The hard bound on the whole step.
 *   idle  — rolling. Re-armed by every sign of worker activity (tool calls,
 *           internal tool logs, progress beats, streamed LLM chunks). Its
 *           purpose is to catch a *stall*, not a slow model.
 *
 * Both windows are reduced by `grace`, so the worker stops `grace` seconds
 * early and still has time to transmit a partial result before the
 * controller's deadline lands and starts rejecting writes.
 *
 * All methods accept an injectable `$now` (seconds, monotonic microtime) so
 * expiry is testable without sleeping.
 */
final class StepBudget
{
    /** Fallbacks used when the controller issues no budget block (old controller). */
    public const DEFAULT_STEP_TIMEOUT = 600.0;

    public const DEFAULT_IDLE_TIMEOUT = 120.0;

    public const DEFAULT_GRACE = 15.0;

    /** Rolling idle window (idle_timeout − grace), never below this. */
    private const MIN_IDLE_WINDOW = 1.0;

    private float $e2eDeadline;

    private float $lastActivityAt;

    /**
     * @param float $idleWindow   seconds of silence tolerated (grace already removed)
     * @param float $e2eWindow    seconds until the hard stop (grace already removed)
     * @param float $grace        the head start kept for transmitting results
     * @param float $idleTimeout  the configured idle budget (pre-grace), for reporting
     * @param bool  $enabled      false = clocks never trip
     * @param float $now          arming instant
     */
    private function __construct(
        private readonly float $idleWindow,
        float $e2eWindow,
        private readonly float $grace,
        private readonly float $idleTimeout,
        private readonly bool $enabled,
        float $now,
    ) {
        $this->e2eDeadline = $now + max(0.0, $e2eWindow);
        $this->lastActivityAt = $now;
    }

    /**
     * Build from the controller's status response (`budget` block).
     *
     * @param array<string, mixed> $statusResponse  the markRunning/status body
     * @param array<string, mixed> $provisionConfig controller-issued worker config,
     *                                              the fallback when the budget block is absent
     * @param array<string, mixed> $overrides       operator overrides (CLI/env) that win over
     *                                              everything — the existing resolveVar chain:
     *                                              step_timeout / step_idle_timeout / step_grace
     */
    public static function fromStatusResponse(array $statusResponse, array $provisionConfig = [], ?float $now = null, array $overrides = []): self
    {
        $now ??= microtime(true);

        $budget = is_array($statusResponse['budget'] ?? null) ? $statusResponse['budget'] : [];

        // Precedence mirrors RunCommand::resolveVar: an explicit operator
        // override (CLI/env) beats the controller, which beats the provision
        // config, which beats the built-in default. The override exists so an
        // operator can make a specific worker MORE conservative locally (or
        // reproduce a deadline in a test) without reconfiguring the fleet;
        // it is a courtesy knob, not a security boundary — the controller's
        // deadlines stay authoritative (§3.9).
        $idleTimeout = self::number($overrides['step_idle_timeout'] ?? null)
            ?? self::number($budget['idle_timeout'] ?? null)
            ?? self::number($provisionConfig['step_idle_timeout'] ?? null)
            ?? self::DEFAULT_IDLE_TIMEOUT;

        $grace = self::number($overrides['step_grace'] ?? null)
            ?? self::number($budget['grace'] ?? null)
            ?? self::number($provisionConfig['step_grace'] ?? null)
            ?? self::DEFAULT_GRACE;

        // e2e_remaining is derived controller-side from the stamped deadline,
        // so it already absorbs the delay between stamping and us reading it.
        // An explicit override is a whole window, not a remainder, so it can
        // only shorten what the controller already allowed.
        $e2eRemaining = self::number($budget['e2e_remaining'] ?? null)
            ?? self::number($provisionConfig['step_timeout'] ?? null)
            ?? self::DEFAULT_STEP_TIMEOUT;

        $overrideE2e = self::number($overrides['step_timeout'] ?? null);
        if (null !== $overrideE2e) {
            $e2eRemaining = min($e2eRemaining, $overrideE2e);
        }

        $grace = max(0.0, $grace);

        return new self(
            max(self::MIN_IDLE_WINDOW, $idleTimeout - $grace),
            max(0.0, $e2eRemaining - $grace),
            $grace,
            max(0.0, $idleTimeout),
            true,
            $now,
        );
    }

    /**
     * A budget that never trips — for runs against an old controller, or when
     * a caller wants the loop without liveness semantics.
     */
    public static function disabled(?float $now = null): self
    {
        return new self(
            PHP_FLOAT_MAX,
            PHP_FLOAT_MAX,
            0.0,
            PHP_FLOAT_MAX,
            false,
            $now ?? microtime(true),
        );
    }

    /**
     * Re-arm the rolling idle window. Call after ANY worker activity the
     * controller can also see (tool call, internal tool log, progress beat) —
     * anything else and our clock drifts behind the controller's.
     */
    public function touch(?float $now = null): void
    {
        $this->lastActivityAt = $now ?? microtime(true);
    }

    public function idleExceeded(?float $now = null): bool
    {
        if (!$this->enabled) {
            return false;
        }

        return (($now ?? microtime(true)) - $this->lastActivityAt) >= $this->idleWindow;
    }

    public function e2eExceeded(?float $now = null): bool
    {
        return $this->enabled && ($now ?? microtime(true)) >= $this->e2eDeadline;
    }

    /**
     * Either clock tripped — stop starting new work.
     */
    public function shouldStop(?float $now = null): bool
    {
        return $this->e2eExceeded($now) || $this->idleExceeded($now);
    }

    /**
     * Why we stopped, for the `complete(partial: true, reason: ...)` payload.
     * Mirrors the controller's own wording (§3.6) so logs line up.
     */
    public function reason(?float $now = null): string
    {
        $now ??= microtime(true);

        if ($this->e2eExceeded($now)) {
            return 'step deadline reached';
        }

        if ($this->idleExceeded($now)) {
            return sprintf('idle timeout: no activity for %ds', (int) ($now - $this->lastActivityAt));
        }

        return 'unknown';
    }

    /**
     * Seconds of silence still tolerated — handed to LlmClient so a stalled
     * stream aborts on the same clock the run loop checks.
     */
    public function idleWindowSeconds(): float
    {
        return $this->enabled ? $this->idleWindow : PHP_FLOAT_MAX;
    }

    /**
     * Seconds until the hard stop (0 once passed).
     */
    public function e2eRemainingSeconds(?float $now = null): float
    {
        return max(0.0, $this->e2eDeadline - ($now ?? microtime(true)));
    }

    public function grace(): float
    {
        return $this->grace;
    }

    public function idleTimeout(): float
    {
        return $this->idleTimeout;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
