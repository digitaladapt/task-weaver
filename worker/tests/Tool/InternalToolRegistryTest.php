<?php

declare(strict_types=1);

namespace TaskWeaverWorker\Tests\Tool;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TaskWeaverWorker\Tool\InternalToolRegistry;
use TaskWeaverWorker\Tool\TerminalTool;

final class InternalToolRegistryTest extends TestCase
{
    public function testRegistersAndResolvesTools(): void
    {
        $registry = new InternalToolRegistry([new TerminalTool()]);

        self::assertTrue($registry->has('terminal'));
        self::assertSame(['terminal'], $registry->names());
        self::assertInstanceOf(TerminalTool::class, $registry->get('terminal'));
    }

    public function testUnknownToolThrows(): void
    {
        $registry = new InternalToolRegistry([]);

        $this->expectException(RuntimeException::class);
        $registry->get('nope');
    }

    public function testSchemasAdvertiseToolInLlmFormat(): void
    {
        $registry = new InternalToolRegistry([new TerminalTool()]);
        $schemas = $registry->schemas();

        self::assertCount(1, $schemas);
        self::assertSame('terminal', $schemas[0]['name']);
        self::assertIsArray($schemas[0]['schema']);
        self::assertArrayHasKey('properties', $schemas[0]['schema']);
    }
}
