<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SchedulerRunCommand;
use App\Repository\TaskRepository;
use App\Service\SchedulerService;
use App\Service\TimezoneService;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * app:scheduler:run daemon semantics.
 *
 * SchedulerService is final, so the real service is driven with a stubbed
 * EM: each tick() calls findDue() exactly once (createQueryBuilder), which
 * doubles as the tick counter.
 *
 * Invariants:
 *   1. Each loop iteration performs one scheduler tick and clears the EM
 *      identity map (long-running hygiene).
 *   2. --max-ticks exits cleanly after N iterations.
 *   3. --interval is clamped to a minimum of 1s.
 */
final class SchedulerRunCommandTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;

    protected function setUp(): void
    {
        // findDue() query chain stubbed to return no due tasks.
        // (Query, not AbstractQuery: QueryBuilder::getQuery() declares a
        // concrete Query return type.)
        $query = $this->createStub(Query::class);
        $query->method('getResult')->willReturn([]);

        $qb = $this->createStub(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('createQueryBuilder')->willReturn($qb);
    }

    private function command(): SchedulerRunCommand
    {
        $scheduler = new SchedulerService(
            $this->em,
            $this->createStub(TaskRepository::class),
            $this->createStub(MessageBusInterface::class),
            new TimezoneService('UTC'),
        );

        return new SchedulerRunCommand($scheduler, $this->em);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(array $input): CommandTester
    {
        $tester = new CommandTester($this->command());
        $tester->execute($input);

        return $tester;
    }

    public function testTicksAndClearsThenExits(): void
    {
        // 3 ticks -> 3 findDue() queries and 3 identity-map clears.
        $this->em->expects($this->exactly(3))->method('createQueryBuilder');
        $this->em->expects($this->exactly(3))->method('clear');

        $tester = $this->execute(['--interval' => '1', '--max-ticks' => '3']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('exiting', $tester->getDisplay());
    }

    public function testSingleTickOneShot(): void
    {
        $this->em->expects($this->once())->method('createQueryBuilder');
        $this->em->expects($this->once())->method('clear');

        $tester = $this->execute(['--max-ticks' => '1']);

        $this->assertSame(0, $tester->getStatusCode());
    }

    public function testIntervalBelowOneIsClamped(): void
    {
        $this->em->expects($this->once())->method('createQueryBuilder');

        $tester = $this->execute(['--interval' => '0', '--max-ticks' => '1']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('interval 1s', $tester->getDisplay());
    }
}
