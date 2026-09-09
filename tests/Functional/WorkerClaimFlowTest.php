<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\McpServer;
use App\Entity\Step;
use App\Entity\Task;
use App\Entity\ToolDef;
use App\Entity\Worker;
use App\Service\ClaimService;

use function assert;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Claim matching end-to-end (SPEC.md → Claiming & Matching).
 *
 * A worker must cover the step's SANDBOX capability tags. External-tool tags
 * (weather, echo, …) are proxied by TaskWeaver for any worker, so a
 * terminal-only worker CAN claim a weather-tagged step — the regression that
 * left weather tasks permanently unclaimed in production.
 */
final class WorkerClaimFlowTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        $em = $this->entityManager();
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        return $em;
    }

    public function testTerminalWorkerClaimsWeatherStepThroughProxy(): void
    {
        $em = $this->entityManager();

        // Live external tool, tagged `weather` on an enabled server.
        $server = new McpServer('weather-api', McpServer::TRANSPORT_OPENAPI, 'https://api.example.com');
        $server->setEnabled(true);
        $em->persist($server);
        $tool = new ToolDef('get_weather', ['weather'], ['type' => 'object']);
        $server->addToolDef($tool);
        $em->persist($tool);

        // Reference worker: terminal-only (production image, no tool tags).
        $worker = new Worker('worker-1', ['terminal']);
        $worker->setApiKey('worker-key');
        $em->persist($worker);

        // Task ready with a weather-tagged final step.
        $task = new Task('Fetch weather', 'Get the weather for London.');
        $task->setStatus(Task::STATUS_READY);
        $em->persist($task);
        $step = new Step('Weather', 'Call get_weather for London.');
        $step->setTags(['weather']);
        $step->setIsFinal(true);
        $step->setSortOrder(0);
        $task->addStep($step);
        $em->flush();

        // The terminal-only worker can claim it: the controller proxies
        // weather; the worker only needs its sandbox capabilities.
        $claim = $this->client->getContainer()->get(ClaimService::class);
        assert($claim instanceof ClaimService);

        $result = $claim->claimFor($worker);

        self::assertNotNull($result);
        self::assertSame($task->getId(), $result['task']->getId());
        self::assertSame($step->getId(), $result['step']->getId());
    }

    public function testWorkerWithoutTerminalCannotClaimTerminalStep(): void
    {
        $em = $this->entityManager();

        $server = new McpServer('weather-api', McpServer::TRANSPORT_OPENAPI, 'https://api.example.com');
        $em->persist($server);
        $tool = new ToolDef('get_weather', ['weather'], ['type' => 'object']);
        $server->addToolDef($tool);
        $em->persist($tool);

        // Sandbox capability required by the step.
        $worker = new Worker('light', []);
        $worker->setApiKey('worker-key');
        $em->persist($worker);

        $task = new Task('Mixed step', 'Run a terminal command and get weather.');
        $task->setStatus(Task::STATUS_READY);
        $em->persist($task);
        $step = new Step('Do things', 'Use terminal and weather.');
        $step->setTags(['terminal', 'weather']);
        $step->setIsFinal(true);
        $step->setSortOrder(0);
        $task->addStep($step);
        $em->flush();

        $claim = $this->client->getContainer()->get(ClaimService::class);
        assert($claim instanceof ClaimService);

        self::assertNull($claim->claimFor($worker));
    }

    public function testDisabledServerToolTagIsNotProxyableAndBlocksClaim(): void
    {
        $em = $this->entityManager();

        // Tool exists but its server is disabled → not a proxyable external
        // tag → `weather` stays a capability requirement → worker can't claim.
        $server = new McpServer('weather-api', McpServer::TRANSPORT_OPENAPI, 'https://api.example.com');
        $server->setEnabled(false);
        $em->persist($server);
        $tool = new ToolDef('get_weather', ['weather'], ['type' => 'object']);
        $server->addToolDef($tool);
        $em->persist($tool);

        $worker = new Worker('worker-1', []);
        $worker->setApiKey('worker-key');
        $em->persist($worker);

        $task = new Task('Fetch weather', 'Get the weather.');
        $task->setStatus(Task::STATUS_READY);
        $em->persist($task);
        $step = new Step('Weather', 'Call get_weather.');
        $step->setTags(['weather']);
        $step->setIsFinal(true);
        $step->setSortOrder(0);
        $task->addStep($step);
        $em->flush();

        $claim = $this->client->getContainer()->get(ClaimService::class);
        assert($claim instanceof ClaimService);

        self::assertNull($claim->claimFor($worker));
    }
}
