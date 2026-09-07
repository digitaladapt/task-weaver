<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SeedCommand;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:seed must be a hard no-op in production — sample data (including a
 * terminal-tagged worker) never lands in a prod database, no matter who
 * calls it or how.
 */
final class SeedCommandProdTest extends TestCase
{
    public function testSeedIsANoOpInProdEvenWhenExplicitlyInvoked(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        // If the command tries to touch the DB in prod, blow up.
        $em->method('persist')->willThrowException(new LogicException('persist() called in prod!'));
        $em->method('flush')->willThrowException(new LogicException('flush() called in prod!'));

        $command = new SeedCommand($em, 'prod');
        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('APP_ENV=prod', $tester->getDisplay());
        self::assertStringContainsString('skipped', $tester->getDisplay());
    }

    public function testSeedStillRunsInDev(): void
    {
        // In dev, the command proceeds past the guard to its idempotency
        // check (dev-echo server lookup) — verify it gets that far without
        // touching the entity manager for writes.
        $em = $this->createStub(EntityManagerInterface::class);
        $repo = $this->createStub(\App\Repository\McpServerRepository::class);
        $repo->method('findOneBy')->willReturn(new \App\Entity\McpServer('dev-echo', 'openapi', 'https://api.example.com'));
        $em->method('getRepository')->willReturn($repo);

        $command = new SeedCommand($em, 'dev');
        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('already seeded', $tester->getDisplay());
    }
}
