<?php

declare(strict_types=1);

namespace App\MCP;

/**
 * A tool invocation result returned by an MCP server client.
 */
final class ToolResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly mixed $data,
        public readonly ?string $error = null,
    ) {
    }

    public static function success(mixed $data): self
    {
        return new self(true, $data);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, $error);
    }
}
