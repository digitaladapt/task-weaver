<?php

declare(strict_types=1);

namespace TaskWeaverWorker;

use RuntimeException;

/**
 * Raised when a streaming LLM call is abandoned because the step ran out of
 * budget mid-generation (docs/step-liveness-plan.md §3.4).
 *
 * Either clock can trip it:
 *  - the idle clock — the stream went silent for longer than the idle window;
 *  - the e2e clock — chunks kept flowing, but the absolute deadline passed.
 *
 * Deliberately NOT a subclass of LlmTransientException: running out of budget
 * is not a transient failure. Retrying would repeat the same wait and spend
 * what little remains, so the run loop salvages the partial `content` /
 * `tool_calls` carried here and submits a truncated-but-completed step.
 */
final class LlmAbortException extends RuntimeException
{
    /**
     * @param array<int, mixed> $partialToolCalls tool-call deltas keyed by index
     */
    public function __construct(
        string $message,
        public readonly ?string $partialContent = null,
        public readonly ?array $partialToolCalls = null,
    ) {
        parent::__construct($message);
    }
}
