<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Event;
use App\Entity\McpServer;
use App\Entity\Step;
use App\Entity\Task;
use App\Entity\ToolDef;
use App\Entity\Worker;
use App\MCP\CredentialResolver;
use App\MCP\McpClientInterface;
use App\MCP\McpClientRegistry;
use App\MCP\ToolResult;
use App\Service\ToolProxyService;

use function assert;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * tool_finished event payload: must always carry the full tool result nested
 * as a structured value (never JSON-in-a-string), null on failure.
 */
final class ToolFinishedEventTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);
        assert($this->em instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testToolFinishedPayloadCarriesNestedResult(): void
    {
        list($server, $tool) = $this->seedTool('weather.get', ['weather'], ['type' => 'object']);

        $task = new Task('Weather', 'Get weather.');
        $task->setStatus(Task::STATUS_READY);
        $this->em->persist($task);

        $step = new Step('Fetch', 'Call weather.');
        $step->setTags(['weather']);
        $step->setIsFinal(true);
        $step->setSortOrder(0);
        $step->setStatus(Step::STATUS_RUNNING);
        $task->addStep($step);

        $worker = new Worker('worker-1', ['terminal']);
        $worker->setApiKey('worker-key');
        $this->em->persist($worker);

        $event = new Event($step, $task, $worker, Event::TYPE_LLM_CALL);
        $event->setApiKey('event-key');
        $this->em->persist($event);
        $this->em->flush();

        // Stub MCP client returns a structured result.
        $registry = new McpClientRegistry([new StubMcpClient()], new CredentialResolver());
        $proxy = new ToolProxyService($this->em, $registry, new \App\Service\ToolResolver($this->em), new NullLogger());

        $result = $proxy->handleToolCall($event, 'weather.get', ['location' => 'London'], null);

        self::assertTrue($result['ok']);
        self::assertSame(['content' => 'Sunny 21°C'], $result['result']);

        // Find the tool_finished event via the repository (the in-memory
        // collection on $step is not refreshed after the proxy's flush).
        $finished = $this->findFinished($event);

        self::assertNotNull($finished);
        $payload = $finished->getPayload();
        self::assertSame('weather.get', $payload['tool']);
        self::assertTrue($payload['ok']);
        self::assertSame(['content' => 'Sunny 21°C'], $payload['result']);
        self::assertArrayNotHasKey('error', $payload['result']);
        // Nested, not a JSON string.
        self::assertIsArray($payload['result']);
    }

    public function testToolFinishedPayloadHasNullResultOnFailure(): void
    {
        list($server, $tool) = $this->seedTool('status.get', ['status'], ['type' => 'object']);

        $task = new Task('Status', 'Check status.');
        $task->setStatus(Task::STATUS_READY);
        $this->em->persist($task);

        $step = new Step('Check', 'Call status.');
        $step->setTags(['status']);
        $step->setIsFinal(true);
        $step->setSortOrder(0);
        $step->setStatus(Step::STATUS_RUNNING);
        $task->addStep($step);

        $worker = new Worker('worker-1', ['terminal']);
        $worker->setApiKey('worker-key');
        $this->em->persist($worker);

        $event = new Event($step, $task, $worker, Event::TYPE_LLM_CALL);
        $event->setApiKey('event-key');
        $this->em->persist($event);
        $this->em->flush();

        $registry = new McpClientRegistry([new FailingMcpClient()], new CredentialResolver());
        $proxy = new ToolProxyService($this->em, $registry, new \App\Service\ToolResolver($this->em), new NullLogger());

        $result = $proxy->handleToolCall($event, 'status.get', [], null);

        self::assertFalse($result['ok']);

        $finished = $this->findFinished($event);

        self::assertNotNull($finished);
        $payload = $finished->getPayload();
        self::assertFalse($payload['ok']);
        self::assertNull($payload['result']);
        self::assertIsString($payload['error']);
    }

    private function findFinished(Event $seedEvent): ?Event
    {
        $repo = $this->em->getRepository(Event::class);
        $events = $repo->findBy(['step' => $seedEvent->getStep()->getId()->toRfc4122()]);
        foreach ($events as $e) {
            if (Event::TYPE_TOOL_FINISHED === $e->getType()) {
                return $e;
            }
        }

        return null;
    }

    /**
     * @return array{0: McpServer, 1: ToolDef}
     */
    private function seedTool(string $name, array $tags, array $schema): array
    {
        $server = new McpServer('demo', McpServer::TRANSPORT_OPENAPI, 'http://127.0.0.1:9999');
        $server->setEnabled(true);
        $this->em->persist($server);

        $tool = new ToolDef($name, $tags, $schema);
        $server->addToolDef($tool);
        $this->em->persist($tool);

        $this->em->flush();

        return [$server, $tool];
    }
}

final class StubMcpClient implements McpClientInterface
{
    public function supports(McpServer $server): bool
    {
        return true;
    }

    public function call(McpServer $server, ToolDef $toolDef, array $arguments, array $env): ToolResult
    {
        return ToolResult::success(['content' => 'Sunny 21°C']);
    }

    public function listTools(McpServer $server, array $env): array
    {
        return [];
    }
}

final class FailingMcpClient implements McpClientInterface
{
    public function supports(McpServer $server): bool
    {
        return true;
    }

    public function call(McpServer $server, ToolDef $toolDef, array $arguments, array $env): ToolResult
    {
        return ToolResult::failure('upstream 503: status endpoint timed out');
    }

    public function listTools(McpServer $server, array $env): array
    {
        return [];
    }
}
