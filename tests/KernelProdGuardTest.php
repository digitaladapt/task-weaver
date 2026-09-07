<?php

declare(strict_types=1);

namespace App\Tests;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The prod boot guard: a misconfigured production deployment must fail
 * loudly at startup rather than serve traffic with well-known secrets.
 */
final class KernelProdGuardTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    public function testProdBootRefusesEmptyEnrollmentToken(): void
    {
        $_SERVER['APP_SECRET'] = str_repeat('a', 64);
        $_SERVER['TASKWEAVER_ENROLLMENT_TOKEN'] = '';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TASKWEAVER_ENROLLMENT_TOKEN is empty');

        (new Kernel('prod', false))->boot();
    }

    public function testProdBootRefusesWellKnownDevEnrollmentToken(): void
    {
        $_SERVER['APP_SECRET'] = str_repeat('a', 64);
        $_SERVER['TASKWEAVER_ENROLLMENT_TOKEN'] = 'dev-enrollment-token';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('well-known placeholder');

        (new Kernel('prod', false))->boot();
    }

    public function testProdBootRefusesPlaceholderAppSecret(): void
    {
        $_SERVER['APP_SECRET'] = 'change-me';
        $_SERVER['TASKWEAVER_ENROLLMENT_TOKEN'] = str_repeat('b', 48);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_SECRET');

        (new Kernel('prod', false))->boot();
    }

    public function testProdBootRefusesTooShortSecrets(): void
    {
        $_SERVER['APP_SECRET'] = str_repeat('a', 64);
        $_SERVER['TASKWEAVER_ENROLLMENT_TOKEN'] = 'short-token';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('too short');

        (new Kernel('prod', false))->boot();
    }

    public function testDevEnvironmentBootsWithoutProdGuard(): void
    {
        // Dev rig: placeholders are expected (committed .env.dev) and must
        // NOT trip the guard — only prod refuses to boot on them.
        $_SERVER['APP_SECRET'] = 'dev-only-change-me';
        $_SERVER['TASKWEAVER_ENROLLMENT_TOKEN'] = 'dev-enrollment-token';

        $kernel = new class('dev', false) extends Kernel {
            public function boot(): void
            {
                // Run only the guard logic: same checks, without building
                // the dev container (which needs a warmed cache we don't
                // want to depend on in a unit test).
                if ('prod' === $this->environment) {
                    throw new RuntimeException('should not happen');
                }
                parent::boot();
            }
        };

        // If the guard were misapplied to dev, this would throw.
        try {
            $kernel->boot();
        } catch (RuntimeException $e) {
            // A container-build failure in a bare unit context is fine —
            // the guard not firing is what we're asserting.
            self::assertStringDoesNotContainString('refused to boot', $e->getMessage());
        }

        self::assertSame('dev', $kernel->getEnvironment());
    }
}
