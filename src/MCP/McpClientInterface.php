<?php

declare(strict_types=1);

namespace App\MCP;

use App\Entity\McpServer;
use App\Entity\ToolDef;

/**
 * Minimal MCP server client interface.
 *
 * TaskWeaver only supports two transports (SPEC.md → Tech Stack):
 *   - OpenAPI (REST): tools mapped from an OpenAPI spec
 *   - HTTP streamable MCP: the modern MCP transport (POST /mcp, SSE stream)
 *
 * Implementations hold the transport-specific call logic; secrets are read
 * from the environment at call time (never stored, never returned).
 */
interface McpClientInterface
{
    public function supports(McpServer $server): bool;

    /**
     * Invoke a tool on the server.
     *
     * @param array<string, string> $env       resolved credential env mapping
     * @param array<string, mixed>  $arguments
     */
    public function call(McpServer $server, ToolDef $toolDef, array $arguments, array $env): ToolResult;
}
