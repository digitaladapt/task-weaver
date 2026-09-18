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
use App\Service\TimezoneService;

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
 *
 * HARD NO-OP in production: sample data (including a terminal-tagged
 * worker) must never be seeded into a prod database, even if invoked
 * explicitly (entrypoint, fat-fingered cron, whatever). The command
 * succeeds without doing anything and warns why.
 */
#[AsCommand(name: 'app:seed', description: 'Seed sample tasks, worker, and event timeline for local development')]
final class SeedCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $environment,
        private readonly TimezoneService $timezone = new TimezoneService(),
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->warning('app:seed is dev/test only: APP_ENV=prod, seeding skipped (sample data is never seeded into a production database).');

            return Command::SUCCESS;
        }

        // Idempotent: if the dev-echo server already exists, bail out.
        $existing = $this->em->getRepository(McpServer::class)->findOneBy(['name' => 'dev-echo']);
        if (null !== $existing) {
            $io->warning('Sample data already seeded; skipping.');

            return Command::SUCCESS;
        }

        // --- Sample MCP server + tool defs ---
        $server = new McpServer('dev-echo', McpServer::TRANSPORT_OPENAPI, 'https://api.example.com');
        $server->setCredVars([]);
        $server->setDescription('Local echo/weather/status fixture for dev smoke tests.');
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

        $statusTool = new ToolDef('status.get', ['status']);
        $statusTool->setDescription('Query an external status page for a service.');
        $statusTool->setSchema([
            'type' => 'object',
            'properties' => ['service' => ['type' => 'string', 'description' => 'Service name, e.g. api']],
            'required' => ['service'],
        ]);
        $server->addToolDef($statusTool);

        // --- Local demo MCP server (dev/demo-mcp.php) for end-to-end runs ---
        // Run `php -S 127.0.0.1:9938 dev/demo-mcp.php` and this server's
        // tools are callable for real. The tool gauntlet task below uses
        // these to validate the multistep envelope end-to-end.
        $demoServer = new McpServer('dev-demo', McpServer::TRANSPORT_OPENAPI, 'http://127.0.0.1:9938');
        $demoServer->setCredVars([]);
        $demoServer->setDescription('Local demo tools (echo/random/time) for the seeded tool gauntlet task. Run dev/demo-mcp.php first.');
        $this->em->persist($demoServer);

        $demoEcho = new ToolDef('demo.echo', ['demo-echo', 'echo']);
        $demoEcho->setDescription('Echoes back the given message with a nonce.');
        $demoEcho->setSchema([
            'type' => 'object',
            'properties' => ['message' => ['type' => 'string', 'description' => 'Message to echo']],
            'required' => ['message'],
            'x-mcp' => ['method' => 'POST', 'path' => '/echo', 'body_param' => null],
        ]);
        $demoServer->addToolDef($demoEcho);

        $demoRandom = new ToolDef('demo.random', ['demo-random']);
        $demoRandom->setDescription('Returns n random numbers between 0 and 1000.');
        $demoRandom->setSchema([
            'type' => 'object',
            'properties' => ['n' => ['type' => 'integer', 'description' => 'How many numbers']],
            'required' => ['n'],
            'x-mcp' => ['method' => 'GET', 'path' => '/random/{n}', 'path_params' => ['n']],
        ]);
        $demoServer->addToolDef($demoRandom);

        $demoTime = new ToolDef('demo.time', ['demo-time']);
        $demoTime->setDescription('Returns the current server time in several formats.');
        $demoTime->setSchema([
            'type' => 'object',
            'properties' => [],
            'x-mcp' => ['method' => 'POST', 'path' => '/time'],
        ]);
        $demoServer->addToolDef($demoTime);

        // --- Sample worker (controller-assigned tags; seen recently) ---
        $worker = new Worker('dev-worker', ['terminal', 'weather', 'echo']);
        $worker->markSeen();
        $this->em->persist($worker);

        // --- Sample single-step task (final, ready to claim) ---
        // Left pristine so there's a "ready" example to claim from the UI.
        $task1 = new Task('Say hello', 'Produce a short greeting.');
        $task1->setStatus(Task::STATUS_READY);
        $task1->setTimezone($this->timezone->resolve());
        $this->em->persist($task1);
        $step1 = new Step('Greet', 'Say hello and report it.');
        $step1->setTags(['echo']);
        $step1->setIsFinal(true);
        $step1->setSortOrder(0);
        $task1->addStep($step1);

        // --- Sample multi-step task: ran to completion with a full event log ---
        $task2 = new Task('Daily brief', 'Produce a daily brief from weather.');
        $task2->setTimezone($this->timezone->resolve());
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
        $step2a->setIdleExpiresAt($t3->modify('+120 seconds'));
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
            'model' => 'Qwen3.5-4B',
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
            'ok' => true,
            'result' => $weatherResult,
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
        $step2b->setIdleExpiresAt($t6->modify('+120 seconds'));
        $step2b->setResult([
            'brief' => 'London: 17°C, partly cloudy, 62% humidity. No coat needed.',
        ]);

        $briefResult = $step2b->getResult();

        $e6 = $this->makeEvent($step2b, Event::TYPE_STEP_STARTED, $worker, [
            'expires_at' => $step2b->getExpiresAt()?->format('c'),
        ], $t5);

        $e7 = $this->makeEvent($step2b, Event::TYPE_LLM_CALL, $worker, [
            'model' => 'Qwen3.5-4B',
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

        // --- Tool gauntlet task: 3 parallel tool-call steps + final envelope ---
        // The end-to-end multistep validation task (WORKER.md §5 run shape 2).
        // Each non-final step exercises one demo tool (demo.echo,
        // demo.random, demo.time — dev/demo-mcp.php); the final step
        // consumes all three results via the tool-call-results envelope.
        $task4 = new Task('Tool gauntlet', 'Call three different demo tools in parallel steps, then summarize the results.');
        $task4->setStatus(Task::STATUS_READY);
        $task4->setTimezone($this->timezone->resolve());
        $this->em->persist($task4);

        $g1 = new Step('Echo step', 'Call the demo.echo tool with the message "hello gauntlet" and report what came back.');
        $g1->setTags(['demo-echo']);
        $g1->setIsFinal(false);
        $g1->setSortOrder(0);
        $task4->addStep($g1);

        $g2 = new Step('Random step', 'Call the demo.random tool with n=5 and report the numbers.');
        $g2->setTags(['demo-random']);
        $g2->setIsFinal(false);
        $g2->setSortOrder(1);
        $task4->addStep($g2);

        $g3 = new Step('Time step', 'Call the demo.time tool and report the server time.');
        $g3->setTags(['demo-time']);
        $g3->setIsFinal(false);
        $g3->setSortOrder(2);
        $task4->addStep($g3);

        $g4 = new Step('Gauntlet summary', 'Summarize the results of the three prior steps (echo, random, time).');
        $g4->setTags(['echo']);
        $g4->setIsFinal(true);
        $g4->setSortOrder(3);
        $task4->addStep($g4);

        // --- Sample failure task: external tool call blew up (final step) ---
        // Demonstrates the error/step_failed path: the third-party status API
        // returned 503, so the step failed and the task is marked failed.
        $task3 = new Task('Site status check', 'Check whether the public site is up.');
        $task3->setTimezone($this->timezone->resolve());
        $this->em->persist($task3);

        $step3 = new Step('Check status', 'Query the external status endpoint for the site.');
        $step3->setTags(['status']);
        $step3->setIsFinal(true);
        $step3->setSortOrder(0);
        $task3->addStep($step3);

        $u0 = $now->modify('-18 minutes');   // step starts
        $u1 = $u0->modify('+2 seconds');     // LLM call
        $u2 = $u1->modify('+2 seconds');     // tool requested
        $u3 = $u2->modify('+3 seconds');     // upstream error
        $u4 = $u3->modify('+1 second');      // tool finished (error)
        $u5 = $u4->modify('+2 seconds');     // step failed

        $step3->setStatus(Step::STATUS_FAILED);
        $step3->setStartedAt($u0);
        $step3->setFinishedAt($u5);
        $step3->setExpiresAt($u0->modify('+600 seconds'));
        $step3->setIdleExpiresAt($u4->modify('+120 seconds'));
        $step3->setResult(null);

        $this->makeEvent($step3, Event::TYPE_STEP_STARTED, $worker, [
            'expires_at' => $step3->getExpiresAt()?->format('c'),
        ], $u0);

        $this->makeEvent($step3, Event::TYPE_LLM_CALL, $worker, [
            'model' => 'Qwen3.5-4B',
            'provider' => 'local',
            'prompt_tokens' => 152,
            'completion_tokens' => 20,
        ], $u1);

        $issueEvent = $this->makeEvent($step3, Event::TYPE_TOOL_REQUESTED, $worker, [
            'tool' => 'status.get',
            'arguments' => ['service' => 'public-site'],
        ], $u2);
        $this->addToolCall(
            $issueEvent,
            'status.get',
            ['service' => 'public-site'],
            null,
            'error',
            'op-2'.base_convert((string) $u2->getTimestamp(), 10, 36),
            $u2,
        );

        $this->makeEvent($step3, Event::TYPE_ERROR, $worker, [
            'tool' => 'status.get',
            'error' => 'upstream 503: status endpoint timed out',
        ], $u3);

        $this->makeEvent($step3, Event::TYPE_TOOL_FINISHED, $worker, [
            'tool' => 'status.get',
            'ok' => false,
            'result' => null,
            'error' => 'upstream 503: status endpoint timed out',
            'status' => 'error',
            'duration_ms' => 3020,
        ], $u4);

        $this->makeEvent($step3, Event::TYPE_STEP_FAILED, $worker, [
            'reason' => 'upstream 503: status endpoint timed out',
        ], $u5);

        $task3->setStatus(Task::STATUS_FAILED);
        $task3->touch();

        $this->em->flush();

        $io->success('Seeded sample data.');
        $io->writeln(sprintf('  Task 1: %s (single-step, final, ready) — unclaimed', $task1->getId()->toRfc4122()));
        $io->writeln(sprintf('  Task 2: %s (multi-step: weather + final) — completed with %d events', $task2->getId()->toRfc4122(), count($task2->getEvents())));
        $io->writeln(sprintf('  Task 3: %s (single-step, failure) — failed with %d events', $task3->getId()->toRfc4122(), count($task3->getEvents())));
        $io->writeln(sprintf('  Task 4: %s (tool gauntlet: 3 parallel tool steps + final) — ready, needs demo-mcp server (dev/demo-mcp.php)', $task4->getId()->toRfc4122()));

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
