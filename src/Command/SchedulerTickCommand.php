<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SchedulerService;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the task scheduler tick once.
 *
 * Marks due recurring tasks ready and advances their next_run_at. Intended to
 * be invoked every minute SchedulerRunCommand, which automatically runs in the
 * controller's Docker container.
 *
 * If using application without Docker, suggest running a cron such as this:
 *   * * * * * cd /path/to/taskweaver && bin/console app:scheduler:tick
 */
#[AsCommand(name: 'app:scheduler:tick', description: 'Run the task scheduler tick (check for due recurring tasks)')]
final class SchedulerTickCommand extends Command
{
    public function __construct(
        private readonly SchedulerService $scheduler,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->scheduler->tick(new DateTimeImmutable());

        return Command::SUCCESS;
    }
}
