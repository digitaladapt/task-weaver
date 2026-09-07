<?php

declare(strict_types=1);

namespace TaskWeaverWorker\Tests;

use PHPUnit\Framework\TestCase;
use TaskWeaverWorker\ContextBudget;

final class ContextBudgetTest extends TestCase
{
    public function testFromConfigDefaults(): void
    {
        $budget = ContextBudget::fromConfig([]);

        self::assertSame(6000, $budget->requestSize());
        self::assertSame(1500, $budget->maxTokens());
    }

    public function testFromConfigReadsIssuedValues(): void
    {
        $budget = ContextBudget::fromConfig([
            'context' => ['request_size' => 4000, 'output_buffer_size' => 1000],
        ]);

        self::assertSame(4000, $budget->requestSize());
        self::assertSame(1000, $budget->maxTokens());
    }

    public function testCapResultLeavesSmallResultsAlone(): void
    {
        $budget = ContextBudget::fromConfig([]);
        $result = ['ok' => true, 'output' => 'hello'];

        self::assertSame($result, $budget->capResult($result, 1500));
    }

    public function testCapResultTruncatesLongStrings(): void
    {
        $budget = ContextBudget::fromConfig([]);
        $result = ['ok' => true, 'output' => str_repeat('x', 20_000)];

        $capped = $budget->capResult($result, 100); // ~100 tokens budget

        $encoded = (string) json_encode($capped);
        self::assertLessThan(strlen((string) json_encode($result)), strlen($encoded));
        self::assertStringContainsString('truncated', $encoded);
    }

    public function testPruneHistoryKeepsSystemAndLastMessages(): void
    {
        $budget = ContextBudget::fromConfig([]);
        $messages = [
            ['role' => 'system', 'content' => 'system prompt'],
            ['role' => 'user', 'content' => 'assignment'],
        ];

        self::assertSame($messages, $budget->pruneHistory($messages));
    }

    public function testPruneHistoryDropsOldestMidMessagesWhenOverBudget(): void
    {
        // Tiny budget so pruning must kick in.
        $budget = ContextBudget::fromConfig(['context' => ['request_size' => 1000, 'output_buffer_size' => 256]]);

        $messages = [
            ['role' => 'system', 'content' => 'system'],
            ['role' => 'assistant', 'content' => str_repeat('a', 4000), 'tool_calls' => [['id' => 't1', 'function' => ['name' => 'demo.echo', 'arguments' => []]]]],
            ['role' => 'tool', 'tool_call_id' => 't1', 'content' => str_repeat('r', 4000)],
            ['role' => 'assistant', 'content' => 'final answer that should be kept'],
        ];

        $pruned = $budget->pruneHistory($messages);

        // System prompt always kept.
        self::assertSame('system', $pruned[0]['role']);
        // Final message always kept.
        self::assertSame('final answer that should be kept', $pruned[count($pruned) - 1]['content']);
        // The oversized middle messages were dropped.
        self::assertLessThan(count($messages), count($pruned));
    }

    public function testPruneHistoryNeverOrphansToolResults(): void
    {
        $budget = ContextBudget::fromConfig(['context' => ['request_size' => 1000, 'output_buffer_size' => 256]]);

        $messages = [
            ['role' => 'system', 'content' => 'system'],
            ['role' => 'assistant', 'content' => 'thinking', 'tool_calls' => [['id' => 't1', 'function' => ['name' => 'demo.time', 'arguments' => []]]]],
            ['role' => 'tool', 'tool_call_id' => 't1', 'content' => '{"iso8601":"now"}'],
            ['role' => 'user', 'content' => 'assignment'],
        ];

        $pruned = $budget->pruneHistory($messages);

        // The tool message must never be the first message after system.
        self::assertNotSame('tool', $pruned[1]['role'] ?? '');
    }
}