<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\McpServer;
use App\Entity\Step;
use App\Entity\Task;
use App\Entity\ToolDef;
use App\Repository\ToolDefRepository;
use App\Service\ToolNotAllowedException;
use App\Service\ToolResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Behavior that matters for tool management: removed tools must never be
 * offered to a step or callable, untagged tools are effectively disabled.
 */
final class ToolResolverTest extends TestCase
{
    /**
     * @param ToolDef[] $tools
     */
    private function resolverWith(array $tools): ToolResolver
    {
        $repo = $this->createMock(ToolDefRepository::class);
        $repo->method('findAll')->willReturn($tools);
        $repo->method('findByName')->willReturnCallback(
            static fn (string $name) => array_values(array_filter(
                $tools,
                static fn (ToolDef $t) => $t->getName() === $name,
            ))[0] ?? null,
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(ToolDef::class)->willReturn($repo);

        return new ToolResolver($em);
    }

    private function stepWithTags(array $tags): Step
    {
        $task = new Task('t', '');
        $step = new Step('s', '');
        $step->setTags($tags);
        $task->addStep($step);

        return $step;
    }

    private function server(): McpServer
    {
        return new McpServer('mcp', McpServer::TRANSPORT_OPENAPI, 'http://x');
    }

    public function testRemovedToolIsNotOfferedToStep(): void
    {
        $server = $this->server();
        $live = new ToolDef('weather.get', ['weather'], []);
        $removed = new ToolDef('old.get', ['weather'], []);
        $removed->markRemoved(new DateTimeImmutable());
        $server->addToolDef($live);
        $server->addToolDef($removed);

        $schemas = $this->resolverWith([$live, $removed])->schemasForStep($this->stepWithTags(['weather']));

        self::assertCount(1, $schemas);
        self::assertSame('weather.get', $schemas[0]['name']);
    }

    public function testRemovedToolCannotBeCalled(): void
    {
        $server = $this->server();
        $removed = new ToolDef('old.get', ['weather'], []);
        $removed->markRemoved(new DateTimeImmutable());
        $server->addToolDef($removed);

        $this->expectException(ToolNotAllowedException::class);
        $this->resolverWith([$removed])->resolveAllowed($this->stepWithTags(['weather']), 'old.get');
    }

    public function testUntaggedToolIsNotOffered(): void
    {
        $server = $this->server();
        $untagged = new ToolDef('no-tags', [], []);
        $server->addToolDef($untagged);

        $schemas = $this->resolverWith([$untagged])->schemasForStep($this->stepWithTags(['anything']));

        // No tags = no intersection = effectively disabled.
        self::assertCount(0, $schemas);
    }

    public function testDisabledServerToolsNotOffered(): void
    {
        $server = $this->server();
        $server->setEnabled(false);
        $tool = new ToolDef('x.get', ['weather'], []);
        $server->addToolDef($tool);

        $schemas = $this->resolverWith([$tool])->schemasForStep($this->stepWithTags(['weather']));
        self::assertCount(0, $schemas);

        // And a call is rejected.
        $this->expectException(ToolNotAllowedException::class);
        $this->resolverWith([$tool])->resolveAllowed($this->stepWithTags(['weather']), 'x.get');
    }
}
