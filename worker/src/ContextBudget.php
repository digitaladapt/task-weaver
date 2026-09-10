<?php

declare(strict_types=1);

namespace TaskWeaverWorker;

use function array_slice;
use function count;
use function is_array;
use function is_string;

use const JSON_INVALID_UTF8_SUBSTITUTE;

use function strlen;

/**
 * Context budget manager (WORKER.md §6 → Context budget).
 *
 * max-context = request-size + output-buffer-size. The step's request
 * (grounding + assignment + pruned history + current tool results) must stay
 * within request-size, so the model always has output-buffer-size free to
 * generate its response.
 *
 * Tokens are estimated: 1 token ≈ 4 chars (a standard heuristic for English
 * text + JSON; conservative enough for prompts that are mostly ASCII).
 */
final class ContextBudget
{
    private const CHARS_PER_TOKEN = 4;

    public function __construct(
        private readonly int $requestSize,
        private readonly int $outputBufferSize,
    ) {
    }

    public static function fromConfig(array $config): self
    {
        $context = is_array($config['context'] ?? null) ? $config['context'] : [];

        return new self(
            max(1000, (int) ($context['request_size'] ?? 6000)),
            max(256, (int) ($context['output_buffer_size'] ?? 1500)),
        );
    }

    /**
     * Token budget for generation — sent as max_tokens on every call.
     */
    public function maxTokens(): int
    {
        return $this->outputBufferSize;
    }

    public function requestSize(): int
    {
        return $this->requestSize;
    }

    public function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / self::CHARS_PER_TOKEN);
    }

    /**
     * Estimate the token cost of a message array (JSON-encoded).
     *
     * @param array<string, mixed> $message
     */
    public function estimateMessageTokens(array $message): int
    {
        return $this->estimateTokens((string) (json_encode($message, JSON_INVALID_UTF8_SUBSTITUTE) ?: ''));
    }

    /**
     * Estimate the token cost of a tool-call result being fed back.
     */
    public function estimateResultTokens(array $result): int
    {
        return $this->estimateTokens((string) (json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE) ?: ''));
    }

    /**
     * Trim a tool-call result's JSON payload so it stays within the given
     * token share of the request budget. Long string values are truncated;
     * deep arrays are flattened to a summary. Order: keep keys, cap values.
     *
     * @param array<string, mixed> $result
     * @param int                  $maxTokens per-result cap
     *
     * @return array<string, mixed>
     */
    public function capResult(array $result, int $maxTokens): array
    {
        $encoded = (string) (json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}');
        if ($this->estimateTokens($encoded) <= $maxTokens) {
            return $result;
        }

        // Too big — deep-truncate string values, then hard-truncate the
        // whole payload if still over.
        $capped = $this->truncateStrings($result, max(64, $maxTokens * 2));

        $encoded = (string) (json_encode($capped, JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}');
        if ($this->estimateTokens($encoded) <= $maxTokens) {
            return $capped;
        }

        // Still too big: hard-truncate the encoded form and note it.
        $chars = max(256, $maxTokens * self::CHARS_PER_TOKEN);
        $hard = substr($encoded, 0, $chars);

        return [
            'truncated' => true,
            'payload' => $hard,
        ];
    }

    /**
     * Recursively truncate long string values in an array.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function truncateStrings(array $data, int $maxCharsPerValue): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value) && strlen($value) > $maxCharsPerValue) {
                $data[$key] = substr($value, 0, $maxCharsPerValue).'…[truncated]';
            } elseif (is_array($value)) {
                $data[$key] = $this->truncateStrings($value, $maxCharsPerValue);
            }
        }

        return $data;
    }

    /**
     * Prune the message history (oldest first, never the system prompt or
     * the current user/assignment message) so the total request fits within
     * the request budget.
     *
     * Strategy (static head + capped tail, task-loop inspired):
     *  1. Keep the system message and the last message always.
     *  2. Drop oldest mid-conversation messages first (assistant + tool
     *     result pairs together) until the total fits.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param int                              $toolSchemaTokens token cost of the tool schemas
     *
     * @return array<int, array<string, mixed>>
     */
    public function pruneHistory(array $messages, int $toolSchemaTokens = 0): array
    {
        $budget = $this->requestSize - $toolSchemaTokens;

        $total = $toolSchemaTokens;
        foreach ($messages as $message) {
            $total += $this->estimateMessageTokens($message);
        }

        if ($total <= $budget || count($messages) <= 2) {
            return $messages;
        }

        // Drop from index 1 (keep the system prompt at 0) up to (but not
        // including) the last message. Tool-result messages must not be
        // orphaned: if we drop an assistant message with tool_calls, we must
        // also drop its trailing tool results.
        $head = [$messages[0]];
        $tailStart = max(1, count($messages) - 1);
        $mid = array_slice($messages, 1, $tailStart - 1);
        $tail = array_slice($messages, $tailStart);

        // Walk the mid-section from the end (most recent first) and keep as
        // much as fits; the oldest that doesn't fit gets dropped, and any
        // tool-result messages before a dropped assistant tool_calls message
        // get dropped with it.
        $kept = [];
        $running = 0;
        foreach ($this->estimateMessages($head) as $cost) {
            $running += $cost;
        }
        foreach ($this->estimateMessages($tail) as $cost) {
            $running += $cost;
        }

        for ($i = count($mid) - 1; $i >= 0; --$i) {
            $cost = $this->estimateMessageTokens($mid[$i]);
            if ($running + $cost <= $budget) {
                array_unshift($kept, $mid[$i]);
                $running += $cost;
                continue;
            }

            // Doesn't fit — stop keeping older messages.
            break;
        }

        // Orphan guard: if the oldest kept message is a tool result whose
        // assistant tool_calls message was dropped, drop it too.
        while ([] !== $kept && ($kept[0]['role'] ?? '') === 'tool') {
            array_shift($kept);
        }

        return [...$head, ...$kept, ...$tail];
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     *
     * @return array<int, int>
     */
    private function estimateMessages(array $messages): array
    {
        return array_map(fn (array $m): int => $this->estimateMessageTokens($m), $messages);
    }
}
