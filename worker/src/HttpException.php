<?php

declare(strict_types=1);

namespace TaskWeaverWorker;

use function in_array;

use RuntimeException;

/**
 * Raised when the controller rejects a request. Carries the HTTP status so
 * the run loop can implement the abandon-on-denial rule (401/403 → drop the
 * step immediately, no retry, no result report).
 */
final class HttpException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
    ) {
        parent::__construct($message, $status);
    }

    public function isDenial(): bool
    {
        return in_array($this->status, [401, 403], true);
    }
}
