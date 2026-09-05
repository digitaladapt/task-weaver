<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Generates opaque, high-entropy random tokens used as worker API keys and
 * event-scoped keys. Contrast with UUIDs (used for entity ids): API keys are
 * never meant to be human-readable, and they're secrets the holder presents.
 */
final class KeyGenerator
{
    /**
     * Generate a URL-safe random token.
     *
     * @param int $bytes number of random bytes (token length ≈ bytes * 4/3)
     */
    public static function generate(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
