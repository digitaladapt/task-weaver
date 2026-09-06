<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\McpServer;
use App\Entity\ToolDef;
use App\MCP\CredentialResolver;
use App\MCP\DiscoveredTool;
use App\MCP\McpClientInterface;
use App\MCP\McpClientRegistry;
use App\MCP\ToolResult;
use App\Service\ConnectionTester;
use PHPUnit\Framework\TestCase;

final class FakeTestConnectionClient implements McpClientInterface
{
    public function __construct(private readonly bool $fail = false, private readonly string $err = '')
    {
    }

    public function supports(McpServer $server): bool
    {
        return true;
    }

    public function call(McpServer $server, ToolDef $toolDef, array $arguments, array $env): ToolResult
    {
        return ToolResult::success([]);
    }

    public function listTools(McpServer $server, array $env): array
    {
        if ($this->fail) {
            throw new \RuntimeException($this->err);
        }

        return [
            new DiscoveredTool('log', [], [], 'Write a log line'),
            new DiscoveredTool('log_read', [], [], 'Read log lines'),
        ];
    }
}

final class ConnectionTesterTest extends TestCase
{
    private function tester(McpClientInterface $client): ConnectionTester
    {
        return new ConnectionTester(
            new McpClientRegistry([$client], new CredentialResolver()),
            new CredentialResolver(),
        );
    }

    public function testReturnsToolsOnSuccess(): void
    {
        $result = $this->tester(new FakeTestConnectionClient())->test([
            'name' => 'probe',
            'endpoint' => 'http://127.0.0.1:8011/mcp',
            'transport' => 'http',
            'cred_vars' => [],
        ]);

        self::assertSame(true, $result['ok']);
        self::assertSame(2, $result['tools']);
        self::assertSame(['log', 'log_read'], $result['names']);
    }

    public function testReturnsErrorWhenClientFails(): void
    {
        $result = $this->tester(new FakeTestConnectionClient(true, 'connection refused'))->test([
            'name' => 'probe',
            'endpoint' => 'http://127.0.0.1:9999/mcp',
            'transport' => 'http',
            'cred_vars' => [],
        ]);

        self::assertSame(false, $result['ok']);
        self::assertSame('connection refused', $result['error']);
    }

    public function testRejectsMissingEndpoint(): void
    {
        $result = $this->tester(new FakeTestConnectionClient())->test([
            'name' => 'probe',
            'endpoint' => '',
            'transport' => 'http',
            'cred_vars' => [],
        ]);

        self::assertSame(false, $result['ok']);
        self::assertSame('Endpoint is required.', $result['error']);
    }

    public function testRejectsUnknownTransport(): void
    {
        $result = $this->tester(new FakeTestConnectionClient())->test([
            'name' => 'probe',
            'endpoint' => 'http://127.0.0.1:8011/mcp',
            'transport' => 'bogus',
            'cred_vars' => [],
        ]);

        self::assertSame(false, $result['ok']);
        self::assertSame('Unsupported transport.', $result['error']);
    }
}
