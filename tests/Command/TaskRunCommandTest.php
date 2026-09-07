<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\TaskRunCommand;
use App\Entity\Step;
use App\Entity\Task;
use App\Repository\TaskRepository;
use App\Service\SchedulerService;
use App\Service\TaskWorkflowService;
use App\Service\TimezoneService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:task:run mirrors the admin UI's "Run" action from the CLI.
 *
 * Invariants:
 *   1. Happy path: resetForRun + flush -> task ready, exit 0.
 *   2. Running-step guard: a LogicException from resetForRun becomes a
 *      clean error + exit 1 (no flush attempted).
 *   3. --wait polls until a terminal status, then reports it.
 *   4. Unknown task -> clean error, exit 1.
 */
final class TaskRunCommandTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
    }

    private function command(TaskRepository $repo): TaskRunCommand
    {
        // SchedulerService is final, so a real instance is wired in (the
        // test tasks have no schedule, so its cron math is never invoked).
        $scheduler = new SchedulerService(
            $this->em,
            $this->createStub(TaskRepository::class),
            $this->createStub(\Symfony\Component\Messenger\MessageBusInterface::class),
            new TimezoneService('UTC'),
        );

        $workflow = new TaskWorkflowService(
            $this->em,
            $this->createStub(LoggerInterface::class),
            $scheduler,
        );

        return new TaskRunCommand($repo, $workflow, $this->em);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(TaskRepository $repo, array $input): CommandTester
    {
        $tester = new CommandTester($this->command($repo));
        $tester->execute($input);

        return $tester;
    }

    private function repo(?Task $task): TaskRepository
    {
        // Pure data provider (no expectations asserted) -> stub, not mock.
        $repo = $this->createStub(TaskRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $repo->method('find')->willReturn($task);

        return $repo;
    }

    private function taskWithCompletedStep(): Task
    {
        $task = new Task('demo', 'demo task');
        $task->setStatus(Task::STATUS_COMPLETED);
        $step = new Step('do work', 'a step');
        $step->setStatus(Step::STATUS_COMPLETED);
        $task->addStep($step);

        return $task;
    }

    public function testRunQueuesTaskForWorker(): void
    {
        $task = $this->taskWithCompletedStep();
        $this->em->expects($this->once())->method('flush');

        $tester = $this->execute($this->repo($task), [
            'task' => '00000000-0000-0000-0000-000000000001',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('queued for the next available worker', $tester->getDisplay());
        $this->assertSame(Task::STATUS_READY, $task->getStatus());
        $this->assertSame(Step::STATUS_PENDING, $task->getSteps()->first()->getStatus());
    }

    public function testRunningStepIsRejected(): void
    {
        $task = new Task('busy', 'task with a running step');
        $task->setStatus(Task::STATUS_RUNNING);
        $step = new Step('sleep 100', 'long step');
        $step->setStatus(Step::STATUS_RUNNING);
        $task->addStep($step);

        $this->em->expects($this->never())->method('flush');

        $tester = $this->execute($this->repo($task), [
            'task' => '00000000-0000-0000-0000-000000000002',
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('cannot be re-run', $tester->getDisplay());
    }

    public function testUnknownTaskFailsCleanly(): void
    {
        $this->em->expects($this->never())->method('flush');

        $tester = $this->execute($this->repo(null), [
            'task' => 'no-such-task',
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('not found', $tester->getDisplay());
    }

    public function testWaitReturnsCompletedStatus(): void
    {
        $task = $this->taskWithCompletedStep();
        $task->setStatus(Task::STATUS_READY);

        // resetForRun's flush is the moment the task is queued; simulate
        // the worker completing it right then, so the first --wait poll
        // already sees the terminal state (no sleep involved).
        // resetForRun flushes once when queueing the run.
        $this->em->expects($this->once())->method('flush')
            ->willReturnCallback(static function () use ($task): void {
                $task->setStatus(Task::STATUS_COMPLETED);
            });

        $repo = $this->createStub(TaskRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $repo->method('find')->willReturn($task);

        $tester = $this->execute($repo, [
            'task' => '00000000-0000-0000-0000-000000000003',
            '--wait' => '5',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('completed', $tester->getDisplay());
    }
}
