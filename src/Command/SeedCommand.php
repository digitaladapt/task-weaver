<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\McpServer;
use App\Entity\Step;
use App\Entity\Task;
use App\Entity\ToolDef;
use Doctrine\ORM\EntityManagerInterface;

use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds the dev database with a sample task + tools for local smoke testing.
 *
 *   bin/console app:seed
 */
#[AsCommand(name: 'app:seed', description: 'Seed sample tasks and tool defs for local development')]
final class SeedCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Idempotent: if the dev-echo server already exists, bail out.
        $existing = $this->em->getRepository(McpServer::class)->findOneBy(['name' => 'dev-echo']);
        if (null !== $existing) {
            $io->warning('Sample data already seeded; skipping.');

            return Command::SUCCESS;
        }

        // --- Sample MCP server + tool defs ---
        $server = new McpServer('dev-echo', McpServer::TRANSPORT_OPENAPI, 'https://api.example.com');
        $server->setCredVars([]);
        $this->em->persist($server);

        $echoTool = new ToolDef('echo.echo', ['echo']);
        $echoTool->setDescription('Echoes back the given message.');
        $echoTool->setSchema([
            'type' => 'object',
            'properties' => ['message' => ['type' => 'string', 'description' => 'Message to echo']],
            'required' => ['message'],
            'x-mcp' => ['method' => 'POST', 'path' => '/echo', 'body_param' => 'message'],
        ]);
        $server->addToolDef($echoTool);

        $weatherTool = new ToolDef('weather.get', ['weather']);
        $weatherTool->setDescription('Get current weather for a location.');
        $weatherTool->setSchema([
            'type' => 'object',
            'properties' => ['location' => ['type' => 'string', 'description' => 'Location, e.g. London']],
            'required' => ['location'],
        ]);
        $server->addToolDef($weatherTool);

        // --- Sample single-step task (final, ready to claim) ---
        $task1 = new Task('Say hello', 'Produce a short greeting.');
        $task1->setStatus(Task::STATUS_READY);
        $this->em->persist($task1);
        $step1 = new Step('Greet', 'Say hello and report it.');
        $step1->setTags(['echo']);
        $step1->setIsFinal(true);
        $step1->setSortOrder(0);
        $task1->addStep($step1);

        // --- Sample multi-step task (parallel non-final + final, ready) ---
        $task2 = new Task('Daily brief', 'Produce a daily brief from weather.');
        $task2->setStatus(Task::STATUS_READY);
        $this->em->persist($task2);

        $step2a = new Step('Weather', 'Fetch the weather for London.');
        $step2a->setTags(['weather']);
        $step2a->setIsFinal(false);
        $step2a->setSortOrder(0);
        $task2->addStep($step2a);

        $step2b = new Step('Compose brief', 'Compose the final brief from the weather step result.');
        $step2b->setTags(['echo']);
        $step2b->setIsFinal(true);
        $step2b->setSortOrder(1);
        $task2->addStep($step2b);

        $this->em->flush();

        $io->success('Seeded sample data.');
        $io->writeln(sprintf('  Task 1: %s (single-step, final)', $task1->getId()->toRfc4122()));
        $io->writeln(sprintf('  Task 2: %s (multi-step: weather + final)', $task2->getId()->toRfc4122()));

        return Command::SUCCESS;
    }
}
