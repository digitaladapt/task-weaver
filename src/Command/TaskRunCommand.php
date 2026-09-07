<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Task;
use App\Repository\TaskRepository;
use App\Service\TaskWorkflowService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function in_array;

use InvalidArgumentException;

use function json_encode;

use LogicException;

use function sleep;
use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Trigger a task run from the CLI (SPEC.md → Operations).
 *
 *   bin/console app:task:run <task-id-or-name>
 *
 * Mirrors the admin UI's "Run" button: resets the task's steps to pending
 * and marks the task ready, so the next worker claim cycle picks it up.
 * Does NOT wait for a worker by default — pass --wait <seconds> to poll
 * until the task reaches a terminal state (completed/failed) or the
 * timeout elapses.
 *
 * Accepts either the task UUID or its exact name (handy on dev rigs
 * where copying UUIDs from the UI is tedious).
 */
#[AsCommand(name: 'app:task:run', description: 'Trigger a task run (reset steps to pending and mark ready for workers)')]
final class TaskRunCommand extends Command
{
    private const POLL_INTERVAL_SECONDS = 2;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly TaskWorkflowService $workflow,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('task', InputArgument::REQUIRED, 'Task UUID or exact name')
            ->addOption('wait', null, InputOption::VALUE_REQUIRED, 'Poll until the task reaches a terminal state, with a timeout in seconds (0 = no wait)', '0')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON instead of human text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $task = $this->findTask((string) $input->getArgument('task'));
        if (null === $task) {
            $io->error(sprintf('Task "%s" not found.', (string) $input->getArgument('task')));

            return Command::FAILURE;
        }

        if ($task->isDeleted()) {
            $io->error(sprintf('Task "%s" is deleted and cannot run.', $task->getName()));

            return Command::FAILURE;
        }

        try {
            $this->workflow->resetForRun($task);
        } catch (LogicException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        // resetForRun only mutates; the caller owns flushing (same contract
        // as the admin UI's run action).
        $this->em->flush();

        $queued = sprintf('Task "%s" queued for the next available worker.', $task->getName());
        if ($input->getOption('json')) {
            $io->writeln((string) json_encode([
                'task_id' => $task->getId()->toRfc4122(),
                'name' => $task->getName(),
                'status' => $task->getStatus(),
                'queued_at' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
            ]));
        } else {
            $io->success($queued);
        }

        $wait = max(0, (int) $input->getOption('wait'));
        if (0 === $wait) {
            return Command::SUCCESS;
        }

        return $this->waitForTerminalState($task->getId()->toRfc4122(), $wait, (bool) $input->getOption('json'), $io);
    }

    /**
     * Accepts a UUID (any canonical / compact form) or an exact task name.
     */
    private function findTask(string $identifier): ?Task
    {
        // UUID forms are strict: 32 or 36 hex chars. Anything else is
        // treated as a task name — names are freeform, so no name should
        // be able to masquerade as an id.
        if (1 === preg_match('/^[0-9a-fA-F]{32}([0-9a-fA-F]{4})?$/', $identifier)
            || 1 === preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $identifier)) {
            try {
                $task = $this->tasks->find($identifier);
            } catch (InvalidArgumentException) {
                $task = null;
            }
            if ($task instanceof Task) {
                return $task;
            }
        }

        return $this->tasks->findOneBy(['name' => $identifier]);
    }

    private function waitForTerminalState(string $taskId, int $timeoutSeconds, bool $json, SymfonyStyle $io): int
    {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $task = $this->tasks->find($taskId);
            if (!$task instanceof Task) {
                // Shouldn't happen (we held a reference), but be safe.
                $io->error('Task disappeared while waiting.');

                return Command::FAILURE;
            }

            $status = $task->getStatus();
            if (in_array($status, [Task::STATUS_COMPLETED, Task::STATUS_FAILED], true)) {
                if ($json) {
                    $io->writeln((string) json_encode([
                        'task_id' => $taskId,
                        'status' => $status,
                        'finished' => true,
                    ]));
                } else {
                    $method = Task::STATUS_COMPLETED === $status ? 'success' : 'error';
                    $io->{$method}(sprintf('Task "%s" finished with status: %s.', $task->getName(), $status));
                }

                return Task::STATUS_COMPLETED === $status ? Command::SUCCESS : Command::FAILURE;
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        }

        if ($json) {
            $io->writeln((string) json_encode(['task_id' => $taskId, 'status' => $task?->getStatus(), 'finished' => false]));
        } else {
            $io->warning(sprintf('Timed out after %d seconds; task is still "%s".', $timeoutSeconds, (string) ($task?->getStatus() ?? 'unknown')));
        }

        return Command::FAILURE;
    }
}
