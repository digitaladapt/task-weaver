<?php

declare(strict_types=1);

namespace TaskWeaverWorker\Tests\Tool;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TaskWeaverWorker\Tool\TerminalTool;

/**
 * The terminal tool now executes commands for real (inside the worker's
 * sandbox). These tests lock in the safety envelope:
 *  - real commands run and their output is returned;
 *  - blocked patterns are refused outright;
 *  - the timeout kills runaway commands;
 *  - the output cap prevents unbounded payloads.
 *
 * Note: these tests DO execute commands in the test environment. They are
 * intentionally benign (echo/date/marker files under the system temp dir).
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
        self::assertStringContainsString('sandbox', (string) $tool->schema()['description']);
    }

    public function testRunsRealCommand(): void
    {
        $tool = new TerminalTool();

        $result = $tool->run(['command' => 'printf hello']);

        self::assertTrue($result['ok']);
        self::assertSame('hello', $result['output']);
        self::assertSame(0, $result['exit_code']);
    }

    public function testStderrIsCaptured(): void
    {
        $tool = new TerminalTool();

        // sh -c: a command that writes to stderr and exits nonzero.
        $result = $tool->run(['command' => 'sh -c "printf oops 1>&2; exit 3"']);

        self::assertFalse($result['ok']);
        self::assertSame(3, $result['exit_code']);
        self::assertStringContainsString('oops', $result['output'] ?? '');
    }

    public function testMissingCommandRejected(): void
    {
        $tool = new TerminalTool();
        $result = $tool->run([]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('command', $result['error'] ?? '');
    }

    #[DataProvider('provideBlockedPatterns')]
    public function testBlockedPatternsRefused(string $command): void
    {
        $tool = new TerminalTool();

        $result = $tool->run(['command' => $command]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('refused', $result['error'] ?? '');
        // No output field on refusal — nothing ran.
        self::assertArrayNotHasKey('output', $result);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideBlockedPatterns(): iterable
    {
        yield 'wipe root' => ['rm -rf /'];
        yield 'wipe root glob' => ['rm -rf /*'];
        yield 'wipe home' => ['rm -rf ~'];
        yield 'wipe cwd glob' => ['rm -rf ./*'];
        yield 'disguised spacing' => ['echo hi &&  rm   -rf   /'];
        yield 'uppercase' => ['RM -RF /'];
        yield 'fork bomb' => [':(){ :|: & };:'];
        yield 'mkfs' => ['mkfs.ext4 /dev/sda1'];
        yield 'dd zero' => ['dd if=/dev/zero of=/dev/sda'];
        yield 'nsenter' => ['nsenter -t 1 mount'];
        yield 'modprobe' => ['modprobe some-module'];
    }

    public function testTimeoutKillsRunawayCommand(): void
    {
        $tool = new TerminalTool(['timeout_seconds' => 1]);

        $start = microtime(true);
        $result = $tool->run(['command' => 'sleep 30']);
        $elapsed = microtime(true) - $start;

        self::assertFalse($result['ok']);
        self::assertTrue($result['timed_out'] ?? false);
        self::assertLessThan(10, $elapsed, 'timeout must actually kill the process');
    }

    public function testOutputCapTruncates(): void
    {
        $tool = new TerminalTool(['max_output_bytes' => 1024]);

        $result = $tool->run(['command' => 'seq 1 100000']);

        self::assertTrue($result['ok']);
        self::assertLessThanOrEqual(1100, strlen($result['output']));
        self::assertStringContainsString('truncated', $result['output']);
    }
}