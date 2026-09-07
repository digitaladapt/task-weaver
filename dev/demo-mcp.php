<?php

declare(strict_types=1);

/**
 * Local demo MCP server for TaskWeaver end-to-end smoke testing.
 *
 * Exposes three tools (matching the seeded `demo-gauntlet` task's steps):
 *
 *   GET  /openapi.json  — OpenAPI spec describing all three tools
 *   POST /echo          — { message } → echoes it back with a nonce
 *   GET  /random/{n}    — n random numbers (JSON array)
 *   POST /time          — server time in several formats
 *
 * Run it standalone:
 *   php -S 127.0.0.1:9938 dev/demo-mcp.php
 *
 * Then point a McpServer (transport: openapi, endpoint:
 * http://127.0.0.1:9938) at it, Sync from the admin UI, and run the seeded
 * "Tool gauntlet" task. The controller discovers tools via the standard
 * OpenAPI fields (operationId, tags, parameters, requestBody) — no custom
 * extensions needed.
 */

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'GET' && $path === '/openapi.json') {
    respond(200, [
        'openapi' => '3.1.0',
        'info' => ['title' => 'TaskWeaver demo MCP server', 'version' => '1.0.0'],
        'paths' => [
            '/echo' => [
                'post' => [
                    'operationId' => 'demo.echo',
                    'summary' => 'Echo a message back with a nonce.',
                    'tags' => ['demo-echo'],
                    'requestBody' => [
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'message' => ['type' => 'string', 'description' => 'Message to echo'],
                                    ],
                                    'required' => ['message'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/random/{n}' => [
                'get' => [
                    'operationId' => 'demo.random',
                    'summary' => 'Get n random numbers between 0 and 1000.',
                    'tags' => ['demo-random'],
                    'parameters' => [
                        [
                            'name' => 'n',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                            'description' => 'How many numbers',
                        ],
                    ],
                ],
            ],
            '/time' => [
                'post' => [
                    'operationId' => 'demo.time',
                    'summary' => 'Server time in several formats.',
                    'tags' => ['demo-time'],
                ],
            ],
        ],
    ]);
}

$body = json_decode((string) file_get_contents('php://input'), true) ?? [];

if ($method === 'POST' && $path === '/echo') {
    $message = is_string($body['message'] ?? null) ? $body['message'] : '';
    if ($message === '') {
        respond(400, ['error' => 'missing "message"']);
    }
    respond(200, [
        'echo' => $message,
        'nonce' => bin2hex(random_bytes(4)),
        'called_at' => (new DateTimeImmutable())->format('c'),
    ]);
}

if ($method === 'GET' && preg_match('#^/random/(\d+)$#', $path, $m)) {
    $n = min(100, max(1, (int) $m[1]));
    $numbers = [];
    for ($i = 0; $n > $i; $i++) {
        $numbers[] = random_int(0, 1000);
    }
    respond(200, ['count' => $n, 'numbers' => $numbers]);
}

if ($method === 'POST' && $path === '/time') {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    respond(200, [
        'iso8601' => $now->format('c'),
        'date' => $now->format('Y-m-d'),
        'time' => $now->format('H:i:s'),
        'weekday' => $now->format('l'),
    ]);
}

respond(404, ['error' => 'not found']);