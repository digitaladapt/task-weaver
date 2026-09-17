<?php

declare(strict_types=1);

namespace TaskWeaverWorker;

use RuntimeException;

/**
 * A retryable LLM failure: transport error, 5xx, 429, or a malformed
 * response. LlmClient retries these with bounded exponential backoff.
 *
 * When thrown mid-stream, it may carry partial content and tool-call
 * deltas so the caller can salvage what the model already produced.
 *
 * @internal
 */
final class LlmTransientException extends RuntimeException
{
    /** @var string|null */
    public $partialContent;

    /** @var array<int, mixed>|null */
    public $partialToolCalls;

    /** @param array<int, mixed>|null $partialToolCalls */
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $partialContent = null,
        ?array $partialToolCalls = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->partialContent = $partialContent;
        $this->partialToolCalls = $partialToolCalls;
    }
}
