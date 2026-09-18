<?php

declare(strict_types=1);

namespace TaskWeaverWorker\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use TaskWeaverWorker\LlmClient;

/**
 * Mid-stream budget aborts (docs/step-liveness-plan.md §3.4).
 *
 * Symfony reports a stalled SSE stream as isTimeout() chunks, and the whole
 * point of the worker-side clocks is that such a stall does NOT burn the
 * step's remaining budget on retries — the partial output is salvaged and
 * the step completes with `truncated: true`.
 */
final class LlmClientIdleAbortTest extends TestCase
{
    public function testStalledStreamAbortsAndSalvagesPartialContent(): void
    {
        // The mock yields chunks, then an empty string — which Symfony's
        // MockResponse documents as "simulate an idle timeout".
        $chunks = [
            "data: {\"choices\":[{\"delta\":{\"content\":\"Half a sen\"}}]}\n\n",
            "data: {\"choices\":[{\"delta\":{\"content\":\"tence\"}}]}\n\n",
            '', // stall
        ];

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse($chunks, [
            'response_headers' => ['content-type' => 'text/event-stream'],
        ]));

        $client = new LlmClient('http://llm:8080/v1', 'Qwen3.5-4B', null, ['retries' => 3, 'backoff_base_ms' => 1], $http);
        // Arm a zero-length idle window: any silence trips immediately.
        $client->setIdleAbortSeconds(0.001);

        $result = $client->chat([['role' => 'user', 'content' => 'hello']]);

        self::assertSame('Half a sentence', $result['content'], 'partial content must be salvaged');
        self::assertTrue($result['truncated'] ?? false, 'result must be flagged truncated');
        self::assertStringContainsString('idle timeout', (string) ($result['reason'] ?? ''));
    }

    public function testStalledStreamIsNotRetried(): void
    {
        // A stall must NOT consume retries: retrying would repeat the same
        // silence and spend the budget the clocks exist to protect.
        $calls = 0;
        $stalled = [
            "data: {\"choices\":[{\"delta\":{\"content\":\"partial\"}}]}\n\n",
            '',
        ];

        $http = new MockHttpClient(static function () use (&$calls, $stalled): MockResponse {
            ++$calls;

            return new MockResponse($stalled, [
                'response_headers' => ['content-type' => 'text/event-stream'],
            ]);
        });

        $client = new LlmClient('http://llm:8080/v1', 'Qwen3.5-4B', null, ['retries' => 3, 'backoff_base_ms' => 1], $http);
        $client->setIdleAbortSeconds(0.001);

        $result = $client->chat([['role' => 'user', 'content' => 'hello']]);

        self::assertSame(1, $calls, 'a stall must be handled in one attempt, not retried');
        self::assertSame('partial', $result['content']);
        self::assertTrue($result['truncated'] ?? false);
    }

    public function testPartialToolCallsSurviveAStall(): void
    {
        $chunks = [
            "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"call_1\",\"type\":\"function\",\"function\":{\"name\":\"get_weather\",\"arguments\":\"{\\\"city\\\":\"}}]}}]}\n\n",
            '',
        ];

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse($chunks, [
            'response_headers' => ['content-type' => 'text/event-stream'],
        ]));

        $client = new LlmClient('http://llm:8080/v1', 'Qwen3.5-4B', null, ['retries' => 1, 'backoff_base_ms' => 1], $http);
        $client->setIdleAbortSeconds(0.001);

        $result = $client->chat([['role' => 'user', 'content' => 'hello']]);

        self::assertTrue($result['truncated'] ?? false);
        self::assertCount(1, $result['tool_calls'], 'partial tool-call deltas must survive');
        self::assertSame('get_weather', $result['tool_calls'][0]['function']['name']);
    }

    public function testNoAbortWhenTheStreamCompletesNormally(): void
    {
        $chunks = [
            "data: {\"choices\":[{\"delta\":{\"content\":\"complete\"}}]}\n\n",
            "data: [DONE]\n\n",
        ];

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse($chunks, [
            'response_headers' => ['content-type' => 'text/event-stream'],
        ]));

        $client = new LlmClient('http://llm:8080/v1', 'Qwen3.5-4B', null, ['retries' => 1], $http);
        $client->setIdleAbortSeconds(0.001);

        $result = $client->chat([['role' => 'user', 'content' => 'hello']]);

        self::assertSame('complete', $result['content']);
        self::assertArrayNotHasKey('truncated', $result, 'a healthy stream must not be flagged truncated');
    }

    public function testE2eDeadlineAbortsEvenWhileChunksKeepFlowing(): void
    {
        // A verbose model can outrun the absolute deadline without ever
        // stalling: chunks keep arriving, so the idle watchdog never fires.
        // The e2e tripwire must still stop it.
        $chunks = [
            "data: {\"choices\":[{\"delta\":{\"content\":\"still going\"}}]}\n\n",
            "data: {\"choices\":[{\"delta\":{\"content\":\" and going\"}}]}\n\n",
            '',
        ];

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse($chunks, [
            'response_headers' => ['content-type' => 'text/event-stream'],
        ]));

        $client = new LlmClient('http://llm:8080/v1', 'Qwen3.5-4B', null, ['retries' => 1], $http);
        // No idle abort, but the e2e deadline is already in the past.
        $client->setE2eAbortAt(microtime(true) - 1);

        $result = $client->chat([['role' => 'user', 'content' => 'hello']]);

        self::assertTrue($result['truncated'] ?? false, 'e2e deadline must abort mid-generation');
        self::assertStringContainsString('deadline', (string) ($result['reason'] ?? ''));
    }
}
