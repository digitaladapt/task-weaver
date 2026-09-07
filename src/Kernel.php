<?php

declare(strict_types=1);

namespace App;

use function in_array;

use RuntimeException;

use function sprintf;
use function strlen;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

use function trim;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }

    /**
     * Production boot guard: core secrets must be real. The app refuses to
     * boot rather than silently running with a well-known dev value — a
     * misconfigured deployment should fail loudly at startup, not serve
     * traffic with a guessable APP_SECRET or accept the dev enrollment
     * token (which would let anyone provision a worker).
     *
     * Well-known placeholder values are rejected along with empties: any
     * value that appears in a committed example/env file is not a secret.
     */
    public function boot(): void
    {
        if ('prod' === $this->environment) {
            $this->assertProdSecretIsReal('APP_SECRET', (string) ($_SERVER['APP_SECRET'] ?? ''), 32);
            $this->assertProdSecretIsReal('TASKWEAVER_ENROLLMENT_TOKEN', (string) ($_SERVER['TASKWEAVER_ENROLLMENT_TOKEN'] ?? ''), 24);
        }

        parent::boot();
    }

    private function assertProdSecretIsReal(string $name, string $value, int $minLength): void
    {
        // Values that literally appear in committed example/env files.
        $placeholders = [
            'change-me',
            'dev-enrollment-token',
            'test-enrollment-token',
            'replace-me-with-a-long-random-hex-string',
            'replace-me-with-a-long-random-token',
            'dev-only-change-me',
            'build-secret',
        ];

        $trimmed = trim($value);

        if ('' === $trimmed) {
            throw new RuntimeException(sprintf('TaskWeaver refused to boot: %s is empty. Generate a real value (see .env.example) and inject it as an environment variable.', $name));
        }

        if (in_array($trimmed, $placeholders, true)) {
            throw new RuntimeException(sprintf('TaskWeaver refused to boot: %s is set to the well-known placeholder "%s". Generate a real value (see .env.example) and inject it as an environment variable.', $name, $trimmed));
        }

        if (strlen($trimmed) < $minLength) {
            throw new RuntimeException(sprintf('TaskWeaver refused to boot: %s is too short (%d chars, minimum %d). Generate a strong value (see .env.example).', $name, strlen($trimmed), $minLength));
        }
    }
}
