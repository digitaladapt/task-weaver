<?php

declare(strict_types=1);

namespace App\Tests\MCP;

use App\MCP\DiscoveredTool;
use App\MCP\OpenApiToolParser;
use PHPUnit\Framework\TestCase;

final class OpenApiToolParserTest extends TestCase
{
    /**
     * A realistic OpenAPI document mirroring what mcp-server's FastAPI app
     * generates: operations with operationId + tags, path/query params,
     * request bodies, and $ref'd component schemas.
     *
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'mcp-server', 'version' => '0.11.0'],
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
                            [
                                'name' => 'location',
                                'in' => 'path',
                                'required' => true,
                                'description' => 'Location, e.g. London',
                                'schema' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
                '/events' => [
                    'post' => [
                        'operationId' => 'create_event',
                        'tags' => ['calendar'],
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/EventInput'],
                                ],
                            ],
                        ],
                    ],
                ],
                '/repos/{owner}/{repo}/issues' => [
                    'get' => [
                        'operationId' => 'list_issues',
                        'tags' => ['gitea-issues'],
                        'parameters' => [
                            ['name' => 'owner', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                            ['name' => 'repo', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ],
                    ],
                ],
                '/log' => [
                    'post' => [
                        'operationId' => 'log',
                        'tags' => ['commands'],
                        'summary' => 'Append a timestamped message to the log file.',
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'level' => ['type' => 'string', 'enum' => ['info', 'warn', 'error']],
                                            'message' => ['type' => 'string'],
                                        ],
                                        'required' => ['message'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'EventInput' => [
                        'type' => 'object',
                        'properties' => [
                            'summary' => ['type' => 'string', 'description' => 'Event title'],
                            'start' => ['type' => 'string', 'format' => 'date-time'],
                            'attendees' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/Attendee'],
                            ],
                        ],
                        'required' => ['summary', 'start'],
                    ],
                    'Attendee' => [
                        'type' => 'object',
                        'properties' => [
                            'email' => ['type' => 'string', 'format' => 'email'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function testParsesOperationIdsAndTags(): void
    {
        $tools = (new OpenApiToolParser())->parse($this->spec());

        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->name] = $tool;
        }

        self::assertArrayHasKey('get_weather', $byName);
        self::assertArrayHasKey('create_event', $byName);
        self::assertArrayHasKey('list_issues', $byName);
        self::assertArrayHasKey('log', $byName);

        // Tags come from the spec (used for initial population only).
        self::assertSame(['weather'], $byName['get_weather']->tags);
        self::assertSame(['calendar'], $byName['create_event']->tags);
        self::assertSame(['gitea-issues'], $byName['list_issues']->tags);
        self::assertSame(['commands'], $byName['log']->tags);
    }

    public function testBuildsSchemaFromParameters(): void
    {
        $tools = (new OpenApiToolParser())->parse($this->spec());
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->name] = $tool;
        }

        $schema = $byName['get_weather']->schema;
        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('days', $schema['properties']);
        self::assertSame('integer', $schema['properties']['days']['type']);
        self::assertArrayHasKey('location', $schema['properties']);
        self::assertContains('location', $schema['required']);
        self::assertSame('Location, e.g. London', $schema['properties']['location']['description']);
        // days is optional → not required
        self::assertNotContains('days', $schema['required']);
    }

    public function testDereferencesRequestBodySchema(): void
    {
        $tools = (new OpenApiToolParser())->parse($this->spec());
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->name] = $tool;
        }

        $schema = $byName['create_event']->schema;
        self::assertArrayHasKey('summary', $schema['properties']);
        self::assertArrayHasKey('start', $schema['properties']);
        self::assertArrayHasKey('attendees', $schema['properties']);

        // Nested $ref (Attendee inside EventInput.attendees.items) resolved.
        self::assertArrayHasKey('email', $schema['properties']['attendees']['items']['properties']);
        self::assertContains('summary', $schema['required']);
        self::assertContains('start', $schema['required']);
    }

    public function testInlineBodySchemaAndDescription(): void
    {
        $tools = (new OpenApiToolParser())->parse($this->spec());
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->name] = $tool;
        }

        $log = $byName['log'];
        self::assertSame('Append a timestamped message to the log file.', $log->description);
        self::assertArrayHasKey('message', $log->schema['properties']);
        self::assertContains('message', $log->schema['required']);
    }

    public function testSkipsNonHttpKeysAndEmptyPaths(): void
    {
        $spec = $this->spec();
        // A path with only a non-HTTTP key should be ignored.
        $spec['paths']['/weird'] = ['parameters' => []];

        $tools = (new OpenApiToolParser())->parse($spec);
        foreach ($tools as $tool) {
            self::assertNotSame('', $tool->name);
        }
        // No operationId → generated fallback name.
        $spec['paths']['/status']['get'] = ['tags' => ['health']];
        $tools = (new OpenApiToolParser())->parse($spec);
        $names = array_map(static fn (DiscoveredTool $t) => $t->name, $tools);
        self::assertContains('get_status', $names);
    }

    public function testDerivesXMcpEndpointMetadata(): void
    {
        $tools = (new OpenApiToolParser())->parse($this->spec());
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->name] = $tool;
        }

        // GET /weather with query+path params → derived from the operation.
        $weather = $byName['get_weather']->schema['x-mcp'];
        self::assertSame('GET', $weather['method']);
        self::assertSame('/weather', $weather['path']);
        self::assertSame(['days'], $weather['query_params']);
        self::assertSame(['location'], $weather['path_params']);

        // Two path params, no query.
        $issues = $byName['list_issues']->schema['x-mcp'];
        self::assertSame('GET', $issues['method']);
        self::assertSame('/repos/{owner}/{repo}/issues', $issues['path']);
        self::assertSame(['owner', 'repo'], $issues['path_params']);
        self::assertArrayNotHasKey('query_params', $issues);

        // POST with request body → no query/path params, method preserved.
        $log = $byName['log']->schema['x-mcp'];
        self::assertSame('POST', $log['method']);
        self::assertSame('/log', $log['path']);
        self::assertArrayNotHasKey('path_params', $log);
        self::assertArrayNotHasKey('query_params', $log);

        // POST with $ref'd body schema — metadata derives the same way.
        $event = $byName['create_event']->schema['x-mcp'];
        self::assertSame('POST', $event['method']);
        self::assertSame('/events', $event['path']);
    }

    public function testExplicitXMcpOverridesDerivedMetadata(): void
    {
        $spec = $this->spec();
        $spec['paths']['/weather']['get']['x-mcp'] = [
            'path' => '/v2/weather',
            'body_param' => 'args',
        ];

        $tools = (new OpenApiToolParser())->parse($spec);
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->name] = $tool;
        }

        $mcp = $byName['get_weather']->schema['x-mcp'];
        // Overridden keys win.
        self::assertSame('/v2/weather', $mcp['path']);
        self::assertSame('args', $mcp['body_param']);
        // Non-overridden derived keys survive.
        self::assertSame('GET', $mcp['method']);
        self::assertSame(['location'], $mcp['path_params']);
    }

    public function testXMcpServerBlockIsPreserved(): void
    {
        $spec = $this->spec();
        $spec['paths']['/weather']['get']['x-mcp-server'] = [
            'server_url' => 'https://api.example.com',
        ];

        $tools = (new OpenApiToolParser())->parse($spec);
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->name] = $tool;
        }

        self::assertSame(
            ['server_url' => 'https://api.example.com'],
            $byName['get_weather']->schema['x-mcp-server'],
        );
    }

    public function testHandlesRecursiveSchemaWithoutInfiniteLoop(): void
    {
        $spec = [
            'paths' => [
                '/tree' => [
                    'post' => [
                        'operationId' => 'make_tree',
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/Node'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'Node' => [
                        'type' => 'object',
                        'properties' => [
                            'value' => ['type' => 'string'],
                            'children' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/Node'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $tools = (new OpenApiToolParser())->parse($spec);
        self::assertCount(1, $tools);
        $schema = $tools[0]->schema;
        // The recursion resolves a few levels then stops at the depth guard.
        self::assertArrayHasKey('value', $schema['properties']);
        self::assertArrayHasKey('children', $schema['properties']);
    }
}
