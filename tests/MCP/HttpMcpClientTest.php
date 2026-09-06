<?php

declare(strict_types=1);

namespace App\Tests\MCP;

use App\Entity\McpServer;
use App\Entity\ToolDef;
use App\MCP\HttpMcpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Verifies the streamable HTTP MCP protocol sequence HttpMcpClient speaks:
 *
 *   1. initialize handshake (required — a bare tools/list is rejected with
 *      400 "Missing session ID" by MCP streamable-HTTP servers).
 *   2. tools/list carried with the Mcp-Session-Id header and params as a
 *      JSON object ({} — never the list [] which the SDK rejects).
 *   3. tools/call likewise carries the session and object params.
 *
 * Uses MockHttpClient so no network is involved.
 */
final class HttpMcpClientTest extends TestCase
{
    private function server(): McpServer
    {
        return new McpServer('mcp-server', McpServer::TRANSPORT_HTTP, 'http://127.0.0.1:8011/mcp');
    }

    private function initResponse(string $sessionId): MockResponse
    {
        return new MockResponse(
            "event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"protocolVersion\":\"2025-03-26\",\"capabilities\":{\"tools\":{}},\"serverInfo\":{\"name\":\"mcp-server\",\"version\":\"1.0.0\"}}}\n\n",
            ['response_headers' => ['content-type' => 'text/event-stream', 'mcp-session-id' => $sessionId]],
        );
    }

    public function testListToolsPerformsInitializeAndSendsObjectParams(): void
    {
        $list = new MockResponse(
            "event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":2,\"result\":{\"tools\":[{\"name\":\"log\",\"description\":\"Write a log line\",\"inputSchema\":{\"type\":\"object\",\"properties\":{\"message\":{\"type\":\"string\"}}}}]}}\n\n",
            ['response_headers' => ['content-type' => 'text/event-stream']],
        );

        $requests = [];
        $i = 0;
        $responses = [$this->initResponse('sess-123'), $list];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, &$i, $responses) {
            $requests[] = ['method' => $method, 'options' => $options];
            $response = $responses[$i] ?? null;
            ++$i;

            return $response ?? new MockResponse('{}');
        }, 'http://127.0.0.1:8011/mcp');

        $client = new HttpMcpClient($httpClient, new NullLogger());

        $tools = $client->listTools($this->server(), []);

        self::assertCount(2, $requests);
        self::assertCount(1, $tools);
        self::assertSame('log', $tools[0]->name);

        // Request 1: initialize handshake.
        $initBody = $requests[0]['options']['body'] ?? '';
        $initPayload = json_decode($initBody, true);
        self::assertSame('initialize', $initPayload['method'] ?? null);
        self::assertArrayHasKey('params', $initPayload);
        self::assertIsArray($initPayload['params']);
        self::assertSame('2025-03-26', $initPayload['params']['protocolVersion'] ?? null);
        self::assertArrayHasKey('clientInfo', $initPayload['params']);

        // Request 2: tools/list with object params + session id header.
        $listBody = $requests[1]['options']['body'] ?? '';
        $listPayload = json_decode($listBody, true);
        self::assertSame('tools/list', $listPayload['method'] ?? null);
        self::assertSame([], $listPayload['params'] ?? 'NOT-AN-ARRAY', 'tools/list params must serialize as {} (object), not [] (list)');
        $headers = $this->headerLines($requests[1]['options']['headers'] ?? []);
        self::assertSame(['sess-123'], $headers['mcp-session-id'] ?? null);
    }

    public function testCallCarriesSessionIdAndCallsTool(): void
    {
        $call = new MockResponse(
            "event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":2,\"result\":{\"content\":[{\"type\":\"text\",\"text\":\"ok\"}]}}\n\n",
            ['response_headers' => ['content-type' => 'text/event-stream']],
        );

        $requests = [];
        $i = 0;
        $responses = [$this->initResponse('sess-xyz'), $call];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, &$i, $responses) {
            $requests[] = ['method' => $method, 'options' => $options];
            $response = $responses[$i] ?? null;
            ++$i;

            return $response ?? new MockResponse('{}');
        }, 'http://127.0.0.1:8011/mcp');

        $client = new HttpMcpClient($httpClient, new NullLogger());
        $tool = new ToolDef('log', [], []);

        $result = $client->call($this->server(), $tool, ['message' => 'hi'], []);
        self::assertTrue($result->ok, $result->error ?? '');
        self::assertCount(2, $requests);

        $callBody = $requests[1]['options']['body'] ?? '';
        $callPayload = json_decode($callBody, true);
        self::assertSame('tools/call', $callPayload['method'] ?? null);
        self::assertSame('log', $callPayload['params']['name'] ?? null);
        self::assertSame(['message' => 'hi'], $callPayload['params']['arguments'] ?? null);
        $headers = $this->headerLines($requests[1]['options']['headers'] ?? []);
        self::assertSame(['sess-xyz'], $headers['mcp-session-id'] ?? null);
    }

    /**
     * MockHttpClient (this Symfony version) passes headers as raw "Name: value"
     * strings. Normalize to lowercase-name => array-of-values.
     *
     * @param array<string, mixed> $headers
     *
     * @return array<string, array<int, string>>
     */
    private function headerLines(array $headers): array
    {
        $out = [];
        foreach ($headers as $line) {
            if (is_string($line) && str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $out[strtolower(trim($name))][] = trim($value);
            }
        }

        return $out;
    }
}
