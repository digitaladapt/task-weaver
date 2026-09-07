<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\McpServer;
use App\Entity\ToolDef;
use App\MCP\CredentialResolver;
use App\MCP\DiscoveredTool;
use App\MCP\McpClientInterface;
use App\MCP\McpClientRegistry;
use App\MCP\ToolResult;
use App\Service\ToolSyncService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * A fake MCP client that just returns a fixed discovery set, so the real
 * (final) McpClientRegistry can be exercised without a network or DB.
 */
final class FakeDiscoveryClient implements McpClientInterface
{
    /**
     * @param DiscoveredTool[] $tools
     */
    public function __construct(private readonly array $tools)
    {
    }

    public function supports(McpServer $server): bool
    {
        return true;
    }

    public function call(McpServer $server, ToolDef $toolDef, array $arguments, array $env): ToolResult
    {
        return ToolResult::success([]);
    }

    public function listTools(McpServer $server, array $env): array
    {
        return $this->tools;
    }
}

final class ToolSyncServiceTest extends TestCase
{
    private function makeServer(): McpServer
    {
        return new McpServer('mcp-server', McpServer::TRANSPORT_OPENAPI, 'https://mcp.example.com');
    }

    /**
     * @param DiscoveredTool[] $discovered
     */
    private function makeRegistry(array $discovered): McpClientRegistry
    {
        return new McpClientRegistry([new FakeDiscoveryClient($discovered)], new CredentialResolver());
    }

    private function makeService(McpClientRegistry $registry): ToolSyncService
    {
        // persist()/flush() are void; leave them unconfigured so the mock
        // silently accepts any call (PHPUnit cannot configure void returns).
        $em = $this->createStub(EntityManagerInterface::class);

        return new ToolSyncService($em, $registry);
    }

    private function discovered(string $name, array $tags = [], array $schema = [], ?string $desc = null): DiscoveredTool
    {
        return new DiscoveredTool($name, $tags, $schema, $desc);
    }

    public function testCreatesNewToolsWithSpecTags(): void
    {
        $server = $this->makeServer();
        $registry = $this->makeRegistry([
            $this->discovered('weather.get', ['weather'], ['type' => 'object'], 'Get weather'),
            $this->discovered('log', ['commands'], ['type' => 'object']),
        ]);

        $result = $this->makeService($registry)->sync($server);

        self::assertSame(2, $result['created']);
        self::assertSame(0, $result['updated']);
        self::assertSame(2, $result['total']);

        $tools = $server->getToolDefs();
        self::assertCount(2, $tools);

        $weather = null;
        foreach ($tools as $t) {
            if ('weather.get' === $t->getName()) {
                $weather = $t;
            }
        }
        self::assertNotNull($weather);
        // Tags seeded from the spec on FIRST creation.
        self::assertSame(['weather'], $weather->getTags());
        self::assertSame('Get weather', $weather->getDescription());
        self::assertFalse($weather->isRemoved());
    }

    public function testPreservesManualTagsOnUpdate(): void
    {
        $server = $this->makeServer();
        $existing = new ToolDef('weather.get', ['manual-tag', 'extra'], ['type' => 'object', 'properties' => ['old' => []]]);
        $existing->setDescription('Old description');
        $server->addToolDef($existing);

        // Server now returns an updated schema/description.
        $registry = $this->makeRegistry([
            $this->discovered('weather.get', ['weather'], ['type' => 'object', 'properties' => ['lat' => ['type' => 'number']]], 'New description'),
        ]);

        $result = $this->makeService($registry)->sync($server);

        self::assertSame(0, $result['created']);
        self::assertSame(1, $result['updated']);
        self::assertSame(0, $result['removed']);

        $tool = $server->getToolDefs()->first();
        // MANUAL TAGS PRESERVED — not overwritten by spec tags.
        self::assertSame(['manual-tag', 'extra'], $tool->getTags());
        // Definition refreshed.
        self::assertSame('New description', $tool->getDescription());
        self::assertSame(['type' => 'object', 'properties' => ['lat' => ['type' => 'number']]], $tool->getSchema());
        self::assertFalse($tool->isRemoved());
    }

    public function testFlagsVanishedToolsAsRemoved(): void
    {
        $server = $this->makeServer();
        $existing = new ToolDef('old.tool', ['legacy'], []);
        $server->addToolDef($existing);

        // Server no longer exposes old.tool.
        $registry = $this->makeRegistry([
            $this->discovered('new.tool', [], []),
        ]);

        $result = $this->makeService($registry)->sync($server);

        self::assertSame(1, $result['created']);
        self::assertSame(1, $result['removed']);

        $old = null;
        foreach ($server->getToolDefs() as $t) {
            if ('old.tool' === $t->getName()) {
                $old = $t;
            }
        }
        self::assertNotNull($old);
        // Flagged removed, not deleted, tags preserved.
        self::assertTrue($old->isRemoved());
        self::assertSame(['legacy'], $old->getTags());
    }

    public function testRestoresToolThatReappears(): void
    {
        $server = $this->makeServer();
        $existing = new ToolDef('flaky.tool', ['manual'], []);
        $existing->markRemoved(new DateTimeImmutable());
        $server->addToolDef($existing);

        $registry = $this->makeRegistry([
            $this->discovered('flaky.tool', [], ['type' => 'object'], 'Back again'),
        ]);

        $result = $this->makeService($registry)->sync($server);

        self::assertSame(1, $result['restored']);

        $tool = $server->getToolDefs()->first();
        self::assertFalse($tool->isRemoved());
        // Manual tags still preserved even on restore.
        self::assertSame(['manual'], $tool->getTags());
        self::assertSame('Back again', $tool->getDescription());
    }

    public function testEmptyDiscoveryFlagsEverythingRemoved(): void
    {
        $server = $this->makeServer();
        $server->addToolDef(new ToolDef('a.tool', ['a'], []));
        $server->addToolDef(new ToolDef('b.tool', ['b'], []));

        $registry = $this->makeRegistry([]);

        $result = $this->makeService($registry)->sync($server);

        self::assertSame(2, $result['removed']);
        foreach ($server->getToolDefs() as $tool) {
            self::assertTrue($tool->isRemoved());
        }
    }
}
