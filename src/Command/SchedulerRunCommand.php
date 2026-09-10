<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SchedulerService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function max;
use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Long-running scheduler driver (SPEC.md → Scheduling).
 *
 *   bin/console app:scheduler:run [--interval 60]
 *
 * Keeps the app:scheduler:tick logic alive in a loop, so scheduling works
 * with zero external cron: runs in the controller docker container.
 * Each iteration is a full tick —
 * marks due recurring tasks ready and advances their next_run_at.
 *
 * A tick missed while the daemon was down is recovered on the next one:
 * isDue() checks next_run_at <= now, so a due task stays due until the
 * next tick picks it up — downtime never skips an occurrence.
 */
#[AsCommand(name: 'app:scheduler:run', description: 'Run the scheduler driver daemon (ticks on an interval, forever)')]
final class SchedulerRunCommand extends Command
{
    private const DEFAULT_INTERVAL_SECONDS = 60;

    public function __construct(
        private readonly SchedulerService $scheduler,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'Seconds between ticks (default 60)', (string) self::DEFAULT_INTERVAL_SECONDS)
            ->addOption('max-ticks', null, InputOption::VALUE_REQUIRED, 'Exit after N ticks (testing / one-shot; 0 = run forever)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $interval = max(1, (int) $input->getOption('interval'));
        $maxTicks = max(0, (int) $input->getOption('max-ticks'));

        $tick = 0;

        $io->writeln(sprintf('<info>Scheduler driver running — interval %ds</info>', $interval));

        while (true) {
            ++$tick;
            $started = microtime(true);

            $this->scheduler->tick(new DateTimeImmutable());

            // Long-running hygiene: drop the identity map so entities from
            // previous ticks (and their snapshots) don't accumulate for the
            // lifetime of the daemon. tick() flushes internally.
            $this->em->clear();

            if ($io->isVerbose()) {
                $io->writeln(sprintf('[tick %d] due tasks marked ready (%.2fs)', $tick, microtime(true) - $started));
            }

            if ($maxTicks > 0 && $tick >= $maxTicks) {
                $io->writeln(sprintf('<info>Max ticks (%d) reached; exiting.</info>', $maxTicks));

                return Command::SUCCESS;
            }

            // Sleep the remainder of the interval (tick time excluded), so
            // long ticks don't drift the schedule.
            $elapsed = microtime(true) - $started;
            $sleepFor = max(0, $interval - (int) $elapsed);
            if ($sleepFor > 0) {
                sleep($sleepFor);
            }
        }
    }
}
