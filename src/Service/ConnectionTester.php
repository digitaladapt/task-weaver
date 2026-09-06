<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\McpServer;
use App\MCP\CredentialResolver;
use App\MCP\McpClientRegistry;

use function array_map;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function trim;

use Throwable;

/**
 * Runs a "test connection" against an MCP server from raw form data,
 * without persisting anything. Used by the admin server form's Test
 * Connection button to validate endpoint + transport + creds before save.
 */
final class ConnectionTester
{
    public function __construct(
        private readonly McpClientRegistry $clients,
        private readonly CredentialResolver $credentials,
    ) {
    }

    /**
     * Build an in-memory server from submitted form fields and discover its
     * tools. Returns a normalized result (never throws):
     *
     *   success: ['ok' => true,  'tools' => int, 'names' => string[]]
     *   failure: ['ok' => false, 'error' => string]
     *
     * @param array<string, mixed> $data
     *
     * @return array{ok: bool, tools?: int, names?: string[], error?: string}
     */
    public function test(array $data): array
    {
        $endpoint = trim((string) ($data['endpoint'] ?? ''));
        if ('' === $endpoint) {
            return ['ok' => false, 'error' => 'Endpoint is required.'];
        }

        $transport = (string) ($data['transport'] ?? McpServer::TRANSPORT_OPENAPI);
        if (!in_array($transport, McpServer::TRANSPORTS, true)) {
            return ['ok' => false, 'error' => 'Unsupported transport.'];
        }

        $server = new McpServer(
            trim((string) ($data['name'] ?? '')),
            $transport,
            $endpoint,
        );

        $credVars = [];
        $rawCreds = $data['cred_vars'] ?? [];
        if (is_array($rawCreds)) {
            foreach ($rawCreds as $v) {
                $v = trim((string) $v);
                if ('' !== $v) {
                    $credVars[] = $v;
                }
            }
        }
        $server->setCredVars(array_values(array_unique($credVars)));

        try {
            $env = $this->credentials->resolve($server);
            $tools = $this->clients->listTools($server, $env);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return [
            'ok' => true,
            'tools' => count($tools),
            'names' => array_map(static fn ($t) => $t->name, $tools),
        ];
    }
}
