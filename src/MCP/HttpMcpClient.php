<?php

declare(strict_types=1);

namespace App\MCP;

use App\Entity\McpServer;
use App\Entity\ToolDef;

use function is_array;
use function is_string;

use Psr\Log\LoggerInterface;
use RuntimeException;

use function sprintf;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * MCP client for the modern streamable HTTP transport (POST /mcp with
 * JSON-RPC 2.0 over HTTP, SSE response stream).
 *
 * Supports: calls (JSON-RPC method "tools/call"). The response stream is
 * read until the JSON-RPC result frame.
 */
final class HttpMcpClient implements McpClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(McpServer $server): bool
    {
        return McpServer::TRANSPORT_HTTP === $server->getTransport();
    }

    public function listTools(McpServer $server, array $env): array
    {
        $headers = $this->sessionHeaders($server, $env);
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => new \stdClass(),
        ];

        try {
            $response = $this->httpClient->request('POST', $server->getEndpoint(), [
                'headers' => $headers,
                'json' => $payload,
                'timeout' => 30,
            ]);

            $status = $response->getStatusCode();
            $body = $response->getContent(false);

            if ($status >= 400) {
                throw new RuntimeException(sprintf('MCP server responded %d: %s', $status, $this->scrub($body)));
            }

            $result = $this->parseListResult($body);

            $tools = [];
            foreach ($result['tools'] ?? [] as $raw) {
                if (!is_array($raw)) {
                    continue;
                }
                $name = is_string($raw['name'] ?? null) ? $raw['name'] : '';
                if ('' === $name) {
                    continue;
                }

                // The MCP tools/list spec has no native tag concept; carry
                // description + inputSchema through, tags stay empty (manual).
                $schema = $raw['inputSchema'] ?? [];
                $schema = is_array($schema) ? $schema : [];

                $tools[] = new DiscoveredTool(
                    name: $name,
                    tags: [],
                    schema: $schema,
                    description: is_string($raw['description'] ?? null) ? $raw['description'] : null,
                    raw: $raw,
                );
            }

            return $tools;
        } catch (Throwable $e) {
            $this->logger->error('MCP tools/list failed', [
                'server' => $server->getName(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function call(McpServer $server, ToolDef $toolDef, array $arguments, array $env): ToolResult
    {
        try {
            $headers = $this->sessionHeaders($server, $env);

            $payload = [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => $toolDef->getName(),
                    'arguments' => $arguments,
                ],
            ];

            $response = $this->httpClient->request('POST', $server->getEndpoint(), [
                'headers' => $headers,
                'json' => $payload,
                'timeout' => 60,
            ]);

            $status = $response->getStatusCode();
            $body = $response->getContent(false);

            if ($status >= 400) {
                return ToolResult::failure(sprintf(
                    'MCP server responded %d: %s',
                    $status,
                    $this->scrub($body),
                ));
            }

            // Streamable transport may return SSE frames or plain JSON.
            $result = $this->parseResult($body);

            return ToolResult::success($result);
        } catch (Throwable $e) {
            $this->logger->error('MCP HTTP call failed', [
                'tool' => $toolDef->getName(),
                'server' => $server->getName(),
                'error' => $e->getMessage(),
            ]);

            return ToolResult::failure(sprintf('MCP call error: %s', $e->getMessage()));
        }
    }

    /**
     * Base headers plus the MCP session id obtained via the initialize
     * handshake (required by the streamable HTTP transport).
     *
     * @return array<string, string>
     */
    private function sessionHeaders(McpServer $server, array $env): array
    {
        $headers = $this->baseHeaders($server, $env);
        $sessionId = $this->initialize($server, $headers);
        if (null !== $sessionId && '' !== $sessionId) {
            $headers['Mcp-Session-Id'] = $sessionId;
        }

        return $headers;
    }

    /**
     * Perform the MCP initialize handshake and return the session id the
     * server assigned (null for stateless servers that omit it).
     *
     * Streamable-HTTP MCP servers (incl. mcp-server) require an initialize
     * exchange before any other request; a bare tools/list is rejected with
     * 400 "Missing session ID".
     */
    private function initialize(McpServer $server, array $headers): ?string
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => new \stdClass(),
                'clientInfo' => [
                    'name' => 'taskweaver',
                    'version' => '0.1.0',
                ],
            ],
        ];

        $response = $this->httpClient->request('POST', $server->getEndpoint(), [
            'headers' => $headers,
            'json' => $payload,
            'timeout' => 30,
        ]);

        $status = $response->getStatusCode();
        $body = $response->getContent(false);

        if ($status >= 400) {
            throw new RuntimeException(sprintf(
                'MCP initialize failed: server responded %d: %s',
                $status,
                $this->scrub($body),
            ));
        }

        // Fail loudly on an error frame so a broken handshake isn't masked.
        $this->parseListResult($body);

        $sessionIds = $response->getHeaders(false)['mcp-session-id'] ?? [];

        return isset($sessionIds[0]) && is_string($sessionIds[0]) ? $sessionIds[0] : null;
    }

    /**
     * @return array{Content-Type: string, Accept: string, Authorization?: string}
     */
    private function baseHeaders(McpServer $server, array $env): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ];

        // Inject credentials from the environment (names in cred_vars).
        foreach ($server->getCredVars() as $credVar) {
            $value = $env[$credVar] ?? null;
            if (null !== $value && '' !== $value) {
                $headers['Authorization'] ??= 'Bearer '.$value;
                // Other credential placement can be refined per server.
            }
        }

        return $headers;
    }

    /**
     * Parse a tools/list response. Handles both plain JSON and SSE frames.
     *
     * @return array<string, mixed>
     */
    private function parseListResult(string $body): array
    {
        if (str_contains($body, 'data:')) {
            foreach (array_reverse(explode("\n", $body)) as $line) {
                $line = trim($line);
                if (!str_starts_with($line, 'data:')) {
                    continue;
                }
                $json = json_decode(substr($line, 5), true);
                if (is_array($json) && isset($json['result'])) {
                    return $json['result'];
                }
            }
        }

        $json = json_decode($body, true);
        if (is_array($json)) {
            if (isset($json['result'])) {
                return $json['result'];
            }
            if (isset($json['error'])) {
                throw new RuntimeException((string) ($json['error']['message'] ?? 'unknown JSON-RPC error'));
            }
        }

        throw new RuntimeException('Malformed tools/list response');
    }

    /**
     * Parse a JSON-RPC response. Handles both plain JSON and SSE frames
     * (data: {...} lines) from the streamable transport.
     *
     * @return array<string, mixed>
     */
    private function parseResult(string $body): array
    {
        if (str_contains($body, 'data:')) {
            // SSE: pick the last data frame that looks like a JSON-RPC result.
            foreach (array_reverse(explode("\n", $body)) as $line) {
                $line = trim($line);
                if (!str_starts_with($line, 'data:')) {
                    continue;
                }
                $json = json_decode(substr($line, 5), true);
                if (is_array($json) && isset($json['result'])) {
                    return $this->extractContent($json['result']);
                }
            }
        }

        $json = json_decode($body, true);
        if (is_array($json)) {
            if (isset($json['result'])) {
                return $this->extractContent($json['result']);
            }
            if (isset($json['error'])) {
                throw new RuntimeException((string) ($json['error']['message'] ?? 'unknown JSON-RPC error'));
            }
        }

        return ['raw' => $body];
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function extractContent(array $result): array
    {
        if (isset($result['content']) && is_array($result['content'])) {
            $text = '';
            foreach ($result['content'] as $part) {
                if (is_array($part) && isset($part['text'])) {
                    $text .= (string) $part['text'];
                }
            }
            if (isset($result['isError']) && true === $result['isError']) {
                throw new RuntimeException('' !== $text ? $text : 'MCP tool returned an error');
            }

            return ['content' => $text];
        }

        return $result;
    }

    private function scrub(string $body): string
    {
        // Secrets can be reflected back in error bodies; cut to a reasonable
        // length and strip anything that looks like an auth header value.
        $body = substr($body, 0, 1000);
        $body = preg_replace('/Bearer\s+[\w.\-]+/i', 'Bearer ***', $body) ?? $body;

        return $body;
    }
}
