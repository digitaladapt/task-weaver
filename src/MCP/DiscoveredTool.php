<?php

declare(strict_types=1);

namespace App\MCP;

/**
 * A discovered tool definition from an MCP server.
 *
 * Returned by McpClientInterface::listTools() / McpClientRegistry::listTools().
 * The `tags` carry the server's *own* capability tags (e.g. OpenAPI
 * operation tags); TaskWeaver seeds them into a ToolDef only on first
 * creation — later re-syncs preserve manual tags (see ToolSyncService).
 */
final class DiscoveredTool
{
    /**
     * @param string[]             $tags   capability tags (OpenAPI operation tags)
     * @param array<string, mixed> $schema JSON Schema for the tool's arguments
     * @param array<string, mixed> $raw    the raw tool descriptor (for diagnostics)
     */
    public function __construct(
        public readonly string $name,
        public readonly array $tags = [],
        public readonly array $schema = [],
        public readonly ?string $description = null,
        public readonly array $raw = [],
    ) {
    }
}
