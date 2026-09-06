<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Event;
use App\Entity\McpServer;
use App\Entity\Step;
use App\Entity\Task;
use App\Entity\ToolCall;
use App\Entity\ToolDef;
use App\Entity\Worker;

use function count;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds the dev database with a sample task, worker, and a realistic event
 * timeline (tool calls etc.) for local smoke testing / visual verification.
 *
 *   bin/console app:seed
 *
 * Idempotent: bails out early if the dev-echo MCP server already exists.
 */
#[AsCommand(name: 'app:seed', description: 'Seed sample tasks, worker, and event timeline for local development')]
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

        // --- Sample worker (controller-assigned tags; seen recently) ---
        $worker = new Worker('dev-worker', ['terminal', 'weather', 'echo']);
        $worker->markSeen();
        $this->em->persist($worker);

        // --- Sample single-step task (final, ready to claim) ---
        // Left pristine so there's a "ready" example to claim from the UI.
        $task1 = new Task('Say hello', 'Produce a short greeting.');
        $task1->setStatus(Task::STATUS_READY);
        $this->em->persist($task1);
        $step1 = new Step('Greet', 'Say hello and report it.');
        $step1->setTags(['echo']);
        $step1->setIsFinal(true);
        $step1->setSortOrder(0);
        $task1->addStep($step1);

        // --- Sample multi-step task: ran to completion with a full event log ---
        $task2 = new Task('Daily brief', 'Produce a daily brief from weather.');
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

        $now = new DateTimeImmutable();

        // The two steps run ~25s apart; spread over the last ~35 minutes.
        $t0 = $now->modify('-35 minutes');   // Weather step starts
        $t1 = $t0->modify('+3 seconds');     // LLM call
        $t2 = $t1->modify('+2 seconds');     // tool requested
        $t3 = $t2->modify('+4 seconds');     // tool finished
        $t4 = $t3->modify('+3 seconds');     // weather step completed
        $t5 = $now->modify('-27 minutes');   // compose step starts
        $t6 = $t5->modify('+2 seconds');     // LLM call for brief
        $t7 = $now->modify('-26 minutes');   // compose step completed

        // -- Weather step lifecycle --
        $step2a->setStatus(Step::STATUS_COMPLETED);
        $step2a->setStartedAt($t0);
        $step2a->setFinishedAt($t4);
        $step2a->setExpiresAt($t0->modify('+600 seconds'));
        $step2a->setResult([
            'location' => 'London',
            'temperature_c' => 17,
            'condition' => 'partly cloudy',
            'humidity' => 62,
        ]);

        $weatherResult = $step2a->getResult();

        $e1 = $this->makeEvent($step2a, Event::TYPE_STEP_STARTED, $worker, [
            'expires_at' => $step2a->getExpiresAt()?->format('c'),
        ], $t0);

        $e2 = $this->makeEvent($step2a, Event::TYPE_LLM_CALL, $worker, [
            'model' => 'llama3.1',
            'provider' => 'local',
            'prompt_tokens' => 214,
            'completion_tokens' => 48,
        ], $t1);

        $e3 = $this->makeEvent($step2a, Event::TYPE_TOOL_REQUESTED, $worker, [
            'tool' => 'weather.get',
            'arguments' => ['location' => 'London'],
        ], $t2);
        $this->addToolCall($e3, 'weather.get', ['location' => 'London'], $weatherResult, 'success', 'op-1'.base_convert((string) $t2->getTimestamp(), 10, 36), $t2);

        $e4 = $this->makeEvent($step2a, Event::TYPE_TOOL_FINISHED, $worker, [
            'tool' => 'weather.get',
            'duration_ms' => 412,
        ], $t3);

        $e5 = $this->makeEvent($step2a, Event::TYPE_STEP_COMPLETED, $worker, [
            'result' => $weatherResult,
        ], $t4);

        // -- Compose (final) step lifecycle --
        $step2b->setStatus(Step::STATUS_COMPLETED);
        $step2b->setStartedAt($t5);
        $step2b->setFinishedAt($t7);
        $step2b->setExpiresAt($t5->modify('+600 seconds'));
        $step2b->setResult([
            'brief' => 'London: 17°C, partly cloudy, 62% humidity. No coat needed.',
        ]);

        $briefResult = $step2b->getResult();

        $e6 = $this->makeEvent($step2b, Event::TYPE_STEP_STARTED, $worker, [
            'expires_at' => $step2b->getExpiresAt()?->format('c'),
        ], $t5);

        $e7 = $this->makeEvent($step2b, Event::TYPE_LLM_CALL, $worker, [
            'model' => 'llama3.1',
            'provider' => 'local',
            'prompt_tokens' => 389,
            'completion_tokens' => 61,
        ], $t6);

        $e8 = $this->makeEvent($step2b, Event::TYPE_STEP_COMPLETED, $worker, [
            'result' => $briefResult,
        ], $t7);

        // Task finished successfully.
        $task2->setStatus(Task::STATUS_COMPLETED);
        $task2->touch();

        $this->em->flush();

        $io->success('Seeded sample data.');
        $io->writeln(sprintf('  Task 1: %s (single-step, final, ready) — unclaimed', $task1->getId()->toRfc4122()));
        $io->writeln(sprintf('  Task 2: %s (multi-step: weather + final) — completed with %d events', $task2->getId()->toRfc4122(), count($task2->getEvents())));

        return Command::SUCCESS;
    }

    /**
     * Build an Event stamped at an explicit time (Event normally stamps now).
     */
    private function makeEvent(Step $step, string $type, Worker $worker, array $payload, DateTimeImmutable $at): Event
    {
        $event = new Event($step, $step->getTask(), $worker, $type);
        $event->setPayload($payload);
        $event->stamp($at);
        $this->em->persist($event);

        return $event;
    }

    /**
     * Attach a ToolCall to an event, stamped at an explicit time.
     */
    private function addToolCall(
        Event $event,
        string $toolName,
        array $request,
        ?array $response,
        string $status,
        string $idempotencyKey,
        DateTimeImmutable $at,
    ): void {
        $call = new ToolCall($event, $toolName, $request);
        $call->setResponse($response);
        $call->setStatus($status);
        $call->setIdempotencyKey($idempotencyKey);
        $call->stamp($at);
        $event->addToolCall($call);
        $this->em->persist($call);
    }
}
