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

        // A benign probe command. If the safety stub is ever replaced by a real
        // runner, this test must FAIL loudly (the probe command must not be
        // executed). Never use a destructive command like `rm -rf /` here:
        // the moment execution is wired up, this test would destroy the
        // sandbox before it can fail.
        $marker = sys_get_temp_dir() . '/taskweaver-terminal-ran-' . bin2hex(random_bytes(6));
        $command = 'printf ran > ' . escapeshellarg($marker);

        $result = $tool->run(['command' => $command]);

        self::assertTrue($result['ok']);
        self::assertStringContainsString('not executed', $result['output']);
        self::assertStringContainsString('TODO', $result['output']);
        // The stub echoed the command back verbatim.
        self::assertStringContainsString($command, $result['output']);

        // Prove nothing actually ran: the marker file must not exist.
        self::assertFileDoesNotExist($marker);
    }

    public function testMissingCommandRejected(): void
    {
        $tool = new TerminalTool();
        $result = $tool->run([]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('command', $result['error'] ?? '');
    }
}
