<?php

declare(strict_types=1);

namespace TaskWeaverWorker\Tests\Tool;

use PHPUnit\Framework\TestCase;
use TaskWeaverWorker\Tool\TerminalTool;

/**
 * Safety-critical: the terminal tool MUST NOT execute anything until the v1
 * runner is implemented. These tests lock in the echo-stub behaviour so an
 * accidental real-execution regression is caught immediately.
 */
final class TerminalToolTest extends TestCase
{
    public function testNameAndSchema(): void
    {
        $tool = new TerminalTool();

        self::assertSame('terminal', $tool->name());
        self::assertSame('object', $tool->schema()['type']);
        self::assertArrayHasKey('command', $tool->schema()['properties'] ?? []);
        self::assertContains('command', $tool->schema()['required'] ?? []);
    }

    public function testEchoStubNeverExecutes(): void
    {
        $tool = new TerminalTool();
        // This command would be catastrophic if actually run. The stub MUST
        // echo it back and NOT execute it.
        $result = $tool->run(['command' => 'rm -rf / && touch /tmp/terminal-ran']);

        self::assertTrue($result['ok']);
        self::assertStringContainsString('rm -rf /', $result['output']);
        self::assertStringContainsString('not executed', $result['output']);
        self::assertStringContainsString('TODO', $result['output']);

        // Prove nothing ran: the marker file must not exist.
        self::assertFileDoesNotExist('/tmp/terminal-ran');
    }

    public function testMissingCommandRejected(): void
    {
        $tool = new TerminalTool();
        $result = $tool->run([]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('command', $result['error'] ?? '');
    }
}
