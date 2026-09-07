<?php

declare(strict_types=1);

namespace App\MCP;

use App\Entity\McpServer;
use App\Entity\ToolDef;

use function array_key_exists;
use function is_array;

use Psr\Log\LoggerInterface;
use RuntimeException;

use function sprintf;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * MCP client for OpenAPI (REST) transport.
 *
 * The ToolDef schema for a REST-mapped tool carries the endpoint metadata in
 * a conventional subset of OpenAPI:
 *
 *   {
 *     "type": "object",
 *     "properties": { ... },
 *     "x-mcp": {
 *       "method": "GET",
 *       "path": "/weather/{location}",
 *       "path_params": ["location"],
 *       "query_params": [...],      // absent if all params are in the path/body
 *       "body_param": "..."         // optional: wrap args under this key
 *     },
 *     "x-mcp-server": { "server_url": "https://api.example.com" }
 *   }
 *
 * The `x-mcp` block is normally derived automatically from the operation
 * itself by OpenApiToolParser (method, path, param locations), so
 * auto-generated specs (FastAPI et al.) are invocable out of the box.
 * An explicit `x-mcp` on the operation overrides the derived value per-key
 * (e.g. to wrap args under a `body_param`).
 */
final class OpenApiMcpClient implements McpClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(McpServer $server): bool
    {
        return McpServer::TRANSPORT_OPENAPI === $server->getTransport();
    }

    public function listTools(McpServer $server, array $env): array
    {
        $headers = $this->buildHeaders($server, $env);

        $url = rtrim($server->getEndpoint(), '/').'/openapi.json';
        $options = ['timeout' => 30];
        if ([] !== $headers) {
            $options['headers'] = $headers;
        }

        try {
            $response = $this->httpClient->request('GET', $url, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);

            if ($status >= 400) {
                throw new RuntimeException(sprintf('OpenAPI server responded %d: %s', $status, $this->scrub($content)));
            }

            $spec = json_decode($content, true);
            if (!is_array($spec)) {
                throw new RuntimeException('openapi.json is not valid JSON');
            }

            return (new OpenApiToolParser())->parse($spec);
        } catch (Throwable $e) {
            $this->logger->error('OpenAPI discovery failed', [
                'server' => $server->getName(),
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function call(McpServer $server, ToolDef $toolDef, array $arguments, array $env): ToolResult
    {
        $schema = $toolDef->getSchema();
        $mcp = $schema['x-mcp'] ?? null;

        if (!is_array($mcp) || !isset($mcp['path'], $mcp['method'])) {
            return ToolResult::failure(sprintf(
                'Tool "%s" has no x-mcp endpoint metadata; OpenAPI transport requires it.',
                $toolDef->getName(),
            ));
        }

        try {
            $baseUrl = $mcp['server_url'] ?? $server->getEndpoint();
            $path = $mcp['path'];
            $method = strtoupper((string) $mcp['method']);

            $pathParams = $mcp['path_params'] ?? [];
            $queryParams = $mcp['query_params'] ?? [];
            $bodyParam = $mcp['body_param'] ?? null;

            // Build the path, substituting path params.
            foreach ($pathParams as $param) {
                $value = $arguments[$param] ?? null;
                if (null === $value) {
                    throw new RuntimeException(sprintf('Missing path parameter "%s"', $param));
                }
                $path = str_replace('{'.$param.'}', (string) $value, $path);
            }

            $url = rtrim($baseUrl, '/').$path;
            $options = [
                'timeout' => 60,
            ];

            $headers = $this->buildHeaders($server, $env);
            if ([] !== $headers) {
                $options['headers'] = $headers;
            }

            if ('GET' === $method || 'DELETE' === $method) {
                $query = [];
                foreach ($queryParams as $param) {
                    if (array_key_exists($param, $arguments)) {
                        $query[$param] = $arguments[$param];
                    }
                }
                if ([] !== $query) {
                    $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
                }
            } else {
                if (null !== $bodyParam) {
                    $options['json'] = [$bodyParam => $arguments];
                } else {
                    $options['json'] = $arguments;
                }
            }

            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);

            if ($status >= 400) {
                return ToolResult::failure(sprintf(
                    'OpenAPI server responded %d: %s',
                    $status,
                    $this->scrub($content),
                ));
            }

            $decoded = json_decode($content, true);

            return ToolResult::success($decoded ?? ['raw' => $content]);
        } catch (Throwable $e) {
            $this->logger->error('OpenAPI call failed', [
                'tool' => $toolDef->getName(),
                'server' => $server->getName(),
                'error' => $e->getMessage(),
            ]);

            return ToolResult::failure(sprintf('OpenAPI call error: %s', $e->getMessage()));
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(McpServer $server, array $env): array
    {
        $headers = [];
        foreach ($server->getCredVars() as $credVar) {
            $value = $env[$credVar] ?? null;
            if (null !== $value && '' !== $value) {
                $headers['Authorization'] ??= 'Bearer '.$value;
            }
        }

        return $headers;
    }

    private function scrub(string $body): string
    {
        $body = substr($body, 0, 1000);
        $body = preg_replace('/Bearer\s+[\w.\-]+/i', 'Bearer ***', $body) ?? $body;

        return $body;
    }
}
