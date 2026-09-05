<?php

declare(strict_types=1);

namespace App\MCP;

use App\Entity\McpServer;

/**
 * Resolves credential values for an MCP server from the environment.
 *
 * The server records only the *names* of the env vars (cred_vars); the actual
 * secrets live in the environment (SPEC.md → Credentials v1). Values are read
 * at call time, are never persisted, and are never returned to the worker.
 */
final class CredentialResolver
{
    /**
     * @return array<string, string|null> map of cred var name → value
     */
    public function resolve(McpServer $server): array
    {
        $resolved = [];

        foreach ($server->getCredVars() as $var) {
            $resolved[$var] = getenv($var) ?: null;
        }

        return $resolved;
    }
}
