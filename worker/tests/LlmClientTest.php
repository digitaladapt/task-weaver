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
}
