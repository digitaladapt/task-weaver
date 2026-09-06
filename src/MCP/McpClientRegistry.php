<?php

declare(strict_types=1);

namespace App\MCP;

use App\Entity\McpServer;
use App\Entity\ToolDef;
use RuntimeException;

use function sprintf;

/**
 * Dispatches a tool call to the right transport client. Secrets are read
 * from the environment at call time via CredentialResolver; they never
 * touch the database or leave this process except in the outbound request.
 */
final class McpClientRegistry
{
    /**
     * @param iterable<McpClientInterface> $clients
     */
    public function __construct(
        private readonly iterable $clients,
        private readonly CredentialResolver $credentials,
    ) {
    }

    public function call(McpServer $server, ToolDef $toolDef, array $arguments): ToolResult
    {
        foreach ($this->clients as $client) {
            if ($client->supports($server)) {
                $env = $this->credentials->resolve($server);

                return $client->call($server, $toolDef, $arguments, $env);
            }
        }

        return ToolResult::failure(sprintf(
            'No MCP client supports transport "%s" for server "%s"',
            $server->getTransport(),
            $server->getName(),
        ));
    }

    /**
     * Discover the tools a server currently exposes.
     *
     * @return DiscoveredTool[]
     */
    public function listTools(McpServer $server): array
    {
        foreach ($this->clients as $client) {
            if ($client->supports($server)) {
                $env = $this->credentials->resolve($server);

                return $client->listTools($server, $env);
            }
        }

        throw new RuntimeException(sprintf('No MCP client supports transport "%s" for server "%s"', $server->getTransport(), $server->getName()));
    }
}
