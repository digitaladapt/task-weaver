<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Worker;
use App\Repository\WorkerRepository;

/**
 * Resolves the authenticated worker from the presented API key.
 *
 * Worker API keys are ephemeral (Tier-1): issued at provision time, and
 * invalidated when worker settings change. Bearer auth.
 */
final class WorkerAuthService
{
    public function __construct(
        private readonly WorkerRepository $workers,
    ) {
    }

    /**
     * Extract the bearer token from an Authorization header value.
     */
    public function bearerToken(?string $header): ?string
    {
        if (null === $header) {
            return null;
        }

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    public function findWorker(?string $header): ?Worker
    {
        $token = $this->bearerToken($header);
        if (null === $token || '' === $token) {
            return null;
        }

        return $this->workers->findByApiKey($token);
    }
}
