<?php

declare(strict_types=1);

namespace App\Tests\MCP;

use App\Entity\McpServer;
use App\Entity\ToolDef;
use App\MCP\OpenApiMcpClient;
use App\MCP\OpenApiToolParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * End-to-end (discovery → call) verification that auto-generated OpenAPI
 * specs — the kind FastAPI emits, with no hand-written `x-mcp` blocks —
 * yield invocable tools.
 *
 * Regression test for the rig finding: get_weather was discovered and
 * advertised, but every call failed with "no x-mcp endpoint metadata"
 * because OpenApiToolParser dropped endpoint metadata that
 * OpenApiMcpClient::call() requires.
 */
final class OpenApiMcpClientTest extends TestCase
{
    /**
     * A FastAPI-style spec: operationId + tags, query params, $ref'd body
     * schemas — and NO x-mcp blocks anywhere.
     */
    private function fastApiSpec(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'MCP Server', 'version' => '0.11.0'],
            'paths' => [
                '/weather' => [
                    'get' => [
                        'operationId' => 'get_weather',
                        'tags' => ['weather'],
                        'summary' => 'Get Weather',
                        'parameters' => [
                            [
                                'name' => 'days',
                                'in' => 'query',
                                'required' => false,
                                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7],
                            ],
                        ],
                    ],
                ],
                '/log' => [
                    'post' => [
                        'operationId' => 'log',
                        'tags' => ['commands'],
                        'summary' => 'Append to log',
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => ['message' => ['type' => 'string']],
                                        'required' => ['message'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                '/random/{n}' => [
                    'get' => [
                        'operationId' => 'random',
                        'tags' => ['misc'],
                        'parameters' => [
                            ['name' => 'n', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Discover tools from the spec the way OpenApiMcpClient::listTools()
     * does (via the parser), then build ToolDefs the way ToolSyncService
     * does — schema verbatim into storage.
     *
     * @return array<string, ToolDef>
     */
    private function discoverToolDefs(array $spec): array
    {
        $discovered = (new OpenApiToolParser())->parse($spec);

        $tools = [];
        foreach ($discovered as $tool) {
            $tools[$tool->name] = new ToolDef($tool->name, $tool->tags, $tool->schema);
        }

        return $tools;
    }

    public function testFastApiSpecToolsAreInvocable(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{"forecast": "sunny", "temp": 21}');
        }, 'http://127.0.0.1:8627');

        $server = new McpServer('mcp-server-dev', McpServer::TRANSPORT_OPENAPI, 'http://127.0.0.1:8627');
        $tools = $this->discoverToolDefs($this->fastApiSpec());

        $mcpClient = new OpenApiMcpClient($client, new NullLogger());

        // GET with a query param.
        $result = $mcpClient->call($server, $tools['get_weather'], ['days' => 3], []);
        self::assertTrue($result->ok, $result->error ?? '');
        self::assertSame(['forecast' => 'sunny', 'temp' => 21], $result->data);

        self::assertSame('GET', $requests[0]['method']);
        self::assertSame('http://127.0.0.1:8627/weather?days=3', $requests[0]['url']);

        // POST with a JSON body.
        $result = $mcpClient->call($server, $tools['log'], ['message' => 'hello'], []);
        self::assertTrue($result->ok, $result->error ?? '');

        self::assertSame('POST', $requests[1]['method']);
        self::assertSame('http://127.0.0.1:8627/log', $requests[1]['url']);
        // MockHttpClient normalizes 'json' into a serialized 'body'.
        self::assertSame(
            ['message' => 'hello'],
            json_decode((string) ($requests[1]['options']['body'] ?? ''), true),
        );

        // GET with a path param.
        $result = $mcpClient->call($server, $tools['random'], ['n' => 5], []);
        self::assertTrue($result->ok, $result->error ?? '');

        self::assertSame('GET', $requests[2]['method']);
        self::assertSame('http://127.0.0.1:8627/random/5', $requests[2]['url']);
    }
}
