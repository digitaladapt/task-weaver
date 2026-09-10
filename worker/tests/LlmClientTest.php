<?php

declare(strict_types=1);

namespace TaskWeaverWorker\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use TaskWeaverWorker\LlmClient;

/**
 * The LLM channel endpoint contract.
 *
 * Direct channel: the base URL (e.g. http://llm:8080/v1) gets /chat/completions
 * appended — standard OpenAI-compatible.
 *
 * Proxy channel: the controller issues a FULL endpoint (/api/worker/llm) that
 * already accepts the whole chat payload. It must NOT get /chat/completions
 * appended again (that used to POST to /api/worker/llm/chat/completions → 404).
 *
 * Streaming (STREAMING.md): chat() sends `stream: true` by default and
 * assembles SSE deltas into the same shape as the non-streaming endpoint.
 */
final class LlmClientTest extends TestCase
{
    public function testDirectChannelAppendsChatCompletions(): void
    {
        $lastUrl = null;
        $http = new MockHttpClient(static function (string $method, string $url) use (&$lastUrl): MockResponse {
            $lastUrl = $url;

            return new MockResponse((string) json_encode([
                'choices' => [['message' => ['content' => 'hi'], 'finish_reason' => 'stop']],
            ]));
        });

        $client = new LlmClient('http://llm:8080/v1', 'Qwen3.5-4B', null, [], $http);
        $result = $client->chat([['role' => 'user', 'content' => 'hello']]);

        self::assertSame('hi', $result['content']);
        self::assertSame('http://llm:8080/v1/chat/completions', $lastUrl);
    }

    public function testProxyChannelUsesFullEndpointIssuedByController(): void
    {
        $lastUrl = null;
        $http = new MockHttpClient(static function (string $method, string $url) use (&$lastUrl): MockResponse {
            $lastUrl = $url;

            return new MockResponse((string) json_encode([
                'choices' => [['message' => ['content' => 'proxied'], 'finish_reason' => 'stop']],
            ]));
        });

        $client = new LlmClient('http://controller:8080/api/worker/llm', 'Qwen3.5-4B', 'worker-key', ['full_endpoint' => true], $http);
        $result = $client->chat([['role' => 'user', 'content' => 'hello']]);

        self::assertSame('proxied', $result['content']);
        self::assertSame('http://controller:8080/api/worker/llm', $lastUrl);
    }

    public function testStreamingAssemblesContentFromDeltas(): void
    {
        $chunks = [
            "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n",
            "data: {\"choices\":[{\"delta\":{\"content\":\" world\"}}]}\n\n",
            "data: [DONE]\n\n",
        ];

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse($chunks, [
            'response_headers' => ['content-type' => 'text/event-stream'],
        ]));

        $client = new LlmClient('http://llm:8080/v1', 'Qwen3.5-4B', null, [], $http);
        $result = $client->chat([['role' => 'user', 'content' => 'hello']]);

        self::assertSame('Hello world', $result['content']);
        self::assertSame([], $result['tool_calls']);
    }

    public function testStreamingAssemblesToolCallDeltas(): void
    {
        $chunks = [
            "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"call_1\",\"type\":\"function\",\"function\":{\"name\":\"get_weather\",\"arguments\":\"\"}}]}}]}\n\n",
            "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"arguments\":\"{\\\"city\\\"\"}}]}}]}\n\n",
            "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"arguments\":\":\\\"London\\\"}\"}}]}}]}\n\n",
            "data: [DONE]\n\n",
        ];

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse($chunks, [
            'response_headers' => ['content-type' => 'text/event-stream'],
        ]));

        $client = new LlmClient('http://llm:8080/v1', 'Qwen3.5-4B', null, [], $http);
        $result = $client->chat([['role' => 'user', 'content' => 'hello']]);

        self::assertSame('', $result['content']);
        self::assertCount(1, $result['tool_calls']);
        self::assertSame('call_1', $result['tool_calls'][0]['id']);
        self::assertSame('get_weather', $result['tool_calls'][0]['function']['name']);
        self::assertSame('{"city":"London"}', $result['tool_calls'][0]['function']['arguments']);
    }

    public function testStreamingCollectsUsageFromFinalChunk(): void
    {
        $chunks = [
            "data: {\"choices\":[{\"delta\":{\"content\":\"done\"}}]}\n\n",
            "data: {\"choices\":[],\"usage\":{\"prompt_tokens\":10,\"completion_tokens\":4,\"total_tokens\":14}}\n\n",
            "data: [DONE]\n\n",
        ];

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse($chunks, [
            'response_headers' => ['content-type' => 'text/event-stream'],
        ]));

        $client = new LlmClient('http://llm:8080/v1', 'Qwen3.5-4B', null, [], $http);
        $result = $client->chat([['role' => 'user', 'content' => 'hello']]);

        self::assertSame('done', $result['content']);
        self::assertSame(14, $result['usage']['total_tokens']);
    }

    public function testStreamingGracefullyFallsBackToPlainJson(): void
    {
        // An upstream that ignores stream:true and returns a normal body.
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse((string) json_encode([
            'choices' => [['message' => ['content' => 'plain'], 'finish_reason' => 'stop']],
        ])));

        $client = new LlmClient('http://llm:8080/v1', 'Qwen3.5-4B', null, [], $http);
        $result = $client->chat([['role' => 'user', 'content' => 'hello']]);

        self::assertSame('plain', $result['content']);
    }

    public function testStreamingTransientErrorIsRetryable(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;
            if (1 === $calls) {
                return new MockResponse('upstream exploded', [
                    'response_headers' => ['content-type' => 'text/plain'],
                    'http_code' => 502,
                ]);
            }

            return new MockResponse([
                "data: {\"choices\":[{\"delta\":{\"content\":\"recovered\"}}]}\n\n",
                "data: [DONE]\n\n",
            ], ['response_headers' => ['content-type' => 'text/event-stream']]);
        });

        $client = new LlmClient('http://llm:8080/v1', 'Qwen3.5-4B', null, ['retries' => 2, 'backoff_base_ms' => 1], $http);
        $result = $client->chat([['role' => 'user', 'content' => 'hello']]);

        self::assertSame(2, $calls);
        self::assertSame('recovered', $result['content']);
    }

    public function testStreamingMalformedBodyThrowsTransient(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('not json at all', [
            'response_headers' => ['content-type' => 'text/plain'],
        ]));

        $client = new LlmClient('http://llm:8080/v1', 'Qwen3.5-4B', null, ['retries' => 1], $http);
        $this->expectExceptionMessage('malformed stream body');

        $client->chat([['role' => 'user', 'content' => 'hello']]);
    }
}
