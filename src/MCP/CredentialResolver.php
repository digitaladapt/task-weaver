<?php

declare(strict_types=1);

namespace App\MCP;

use App\Entity\McpServer;

use function is_string;

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
            $resolved[$var] = $this->resolveVar($var);
        }

        return $resolved;
    }

    /**
     * Resolve a single credential variable from the environment.
     *
     * Checks the process environment (getenv) so real exported vars work, and
     * falls back to $_ENV/$_SERVER so values loaded from a .env file by
     * symfony/dotenv (which does not call putenv() by default) are honored
     * too. The credential names are dynamic (stored per McpServer in the DB),
     * so they cannot be declared as container env() parameters.
     */
    private function resolveVar(string $var): ?string
    {
        $value = getenv($var);

        if (false === $value || '' === $value) {
            $value = $_ENV[$var] ?? $_SERVER[$var] ?? null;
        }

        if (!is_string($value) || '' === $value) {
            return null;
        }

        return $value;
    }
}
