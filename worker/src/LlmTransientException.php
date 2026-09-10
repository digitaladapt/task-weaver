<?php

declare(strict_types=1);

namespace TaskWeaverWorker;

use RuntimeException;

/**
 * A retryable LLM failure: transport error, 5xx, 429, or a malformed
 * response. LlmClient retries these with bounded exponential backoff.
 *
 * @internal
 */
final class LlmTransientException extends RuntimeException
{
}
