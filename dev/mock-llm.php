<?php

declare(strict_types=1);

/**
 * Mock OpenAI-compatible LLM server for TaskWeaver end-to-end testing
 * without a real model.
 *
 * Behavior is driven by a script of responses delivered in order, stored in
 * a JSON file (see below). Each entry is either:
 *
 *   { "tool_calls": [ { "id": "c1", "function": { "name": "demo.echo",
 *       "arguments": "{\"message\":\"hi\"}" } } ] }
 *     → respond with these tool calls (round continues)
 *
 *   { "content": "final text" }
 *     → respond with plain text (step ends)
 *
 *   { "status": 500 } | { "status": 429 }
 *     → respond with that HTTP status (worker should retry)
 *
 *   { "stream": true }   (optional, per entry)
 *     → stream the entry's response as OpenAI-style SSE frames when the
 *       worker requested stream:true; if the worker didn't request
 *       streaming, the same entry is emitted as a plain JSON response.
 *
 *   { "content": "slow…", "chunk_delay": 2 }
 *     → pause `chunk_delay` seconds between pieces of the response.
 *
 *       CAVEAT: under PHP's built-in server (`php -S`) the response body is
 *       buffered and delivered at once when the script ends, so this models a
 *       model that is slow to *respond*, NOT one that streams slowly. To
 *       exercise the worker's progress beats against genuinely incremental
 *       output you need a streaming SAPI (php-fpm/nginx, frankenphp). The
 *       beats themselves are covered by the controller functional tests
 *       (tests/Functional/StepLivenessTest.php), which drive the endpoint
 *       directly.
 *
 *   { "content": "partial…", "stall_after": 3 }
 *     → emit the content, then go SILENT (no frames, no [DONE]) for
 *       `stall_after` seconds and hang up. Exercises the worker's idle
 *       watchdog: it should abort, salvage the partial content, and submit
 *       the step as `partial: true` (docs/step-liveness-plan.md §3.4/§3.5).
 *       Any positive value works; a large one (60) models an indefinitely
 *       wedged model without freezing the test run.
 *
 * Script file path comes from MOCK_LLM_SCRIPT env var, or defaults to
 * dev/mock-llm-script.json. The script auto-repeats its last entry when
 * exhausted (so long loops can be simulated); POST /__reset restarts the
 * sequence (state file per port).
 *
 * Run: php -S 127.0.0.1:9939 dev/mock-llm.php
 */

$scriptPath = getenv('MOCK_LLM_SCRIPT') ?: __DIR__ . '/mock-llm-script.json';
$stateFile = sys_get_temp_dir() . '/taskweaver-mock-llm-' . (getenv('MOCK_LLM_PORT') ?: '9939') . '.state';

function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Emit one SSE data frame.
 */
function sse_data(string $json): void
{
    echo 'data: ' . $json . "\n\n";
    if (function_exists('flush')) {
        flush();
    }
}

/**
 * Emit the terminal [DONE] frame and end the response.
 */
function sse_done(): never
{
    echo 'data: [DONE]' . "\n\n";
    if (function_exists('flush')) {
        flush();
    }
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Reset the sequence position.
if ($method === 'POST' && $path === '/__reset') {
    @unlink($stateFile);
    respond(200, ['ok' => true]);
}

$script = json_decode((string) file_get_contents($scriptPath), true);
if (!is_array($script)) {
    respond(500, ['error' => 'mock script missing/invalid at ' . $scriptPath]);
}

// Accept either a plain array of entries or {"entries": [...]} with an
// optional comment wrapper.
if (array_is_list($script) === false && isset($script['entries']) && is_array($script['entries'])) {
    $script = $script['entries'];
} elseif (array_is_list($script) === false) {
    respond(500, ['error' => 'mock script must be a list of entries or {"entries": [...]}']);
}

// Advance and persist the cursor.
$pos = (int) (@file_get_contents($stateFile) ?: '0');
$entry = $script[$pos] ?? (count($script) > 0 ? $script[count($script) - 1] : []);
file_put_contents($stateFile, (string) ($pos + 1));

// Consume the request body (worker POSTs chat payloads).
$body = json_decode((string) file_get_contents('php://input'), true) ?? [];

// Only implement chat/completions; anything else 404s.
if ($method !== 'POST' || $path !== '/v1/chat/completions') {
    respond(404, ['error' => 'not found']);
}

// Scripted status codes let us exercise the worker's retry path.
if (isset($entry['status'])) {
    respond((int) $entry['status'], ['error' => 'scripted failure']);
}

// Echo mode: return the last user message (stripped) as the content —
// proves what the worker actually sent (e.g. the envelope in the prompt).
if (!empty($entry['echo_user'])) {
    $lastUser = '';
    foreach (($body['messages'] ?? []) as $m) {
        if (($m['role'] ?? '') === 'user') {
            $lastUser = (string) ($m['content'] ?? '');
        }
    }
    $echoBody = ['id' => 'chatcmpl-mock-echo', 'object' => 'chat.completion', 'created' => time(), 'model' => $body['model'] ?? 'mock-model', 'choices' => [['index' => 0, 'message' => ['content' => $lastUser], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120]];
    if (($body['stream'] ?? false) === true) {
        stream_content($lastUser, $echoBody, $body);
    }
    respond(200, $echoBody);
}

$toolCalls = $entry['tool_calls'] ?? [];
$content = $entry['content'] ?? '';
$userStream = ($body['stream'] ?? false) === true;

if ($userStream) {
    stream_entry($content, $toolCalls, $body, $entry);
}

// Non-streaming: standard JSON response.
$message = [];
if (is_string($content) && $content !== '') {
    $message['content'] = $content;
}
if (is_array($toolCalls) && $toolCalls !== []) {
    $message['tool_calls'] = $toolCalls;
}

respond(200, [
    'id' => 'chatcmpl-mock-' . $pos,
    'object' => 'chat.completion',
    'created' => time(),
    'model' => $body['model'] ?? 'mock-model',
    'choices' => [
        [
            'index' => 0,
            'message' => $message,
            'finish_reason' => $toolCalls === [] ? 'stop' : 'tool_calls',
        ],
    ],
    'usage' => [
        'prompt_tokens' => 100,
        'completion_tokens' => 20,
        'total_tokens' => 120,
    ],
]);

/**
 * Stream a content entry as SSE deltas (chunked to exercise incremental
 * assembly) then a usage frame and [DONE].
 *
 * @param array<string, mixed> $fullBody full non-stream body shape (for id/model)
 * @param array<string, mixed> $request  the worker's chat payload
 */
function stream_content(string $content, array $fullBody, array $request): never
{
    http_response_code(200);
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $model = $request['model'] ?? 'mock-model';
    $id = $fullBody['id'] ?? ('chatcmpl-mock-' . time());
    $created = $fullBody['created'] ?? time();

    // Split content into a few pieces so the worker exercises delta assembly.
    $pieces = chunk_text($content, 5);
    $chunkDelay = $fullBody['chunk_delay'] ?? null;
    foreach ($pieces as $piece) {
        // Slow-model mode: keep trickling output. Every chunk is real progress,
        // so the worker's beats should keep refreshing the idle deadline.
        if (is_numeric($chunkDelay) && (float) $chunkDelay > 0) {
            usleep((int) ((float) $chunkDelay * 1_000_000));
        }
        sse_data((string) json_encode([
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => $created,
            'model' => $model,
            'choices' => [['index' => 0, 'delta' => ['content' => $piece], 'finish_reason' => null]],
        ]));
    }

    // Stall mode: emit what we have, then go silent and hang up — no
    // [DONE], no usage frame. This is the "model accepted the request and
    // went quiet" case the worker's idle watchdog exists to catch.
    $stallAfter = $fullBody['stall_after'] ?? null;
    if (is_numeric($stallAfter) && (float) $stallAfter > 0) {
        @ob_flush();
        sleep((int) ceil((float) $stallAfter));
        exit;
    }

    sse_data((string) json_encode([
        'id' => $id,
        'object' => 'chat.completion.chunk',
        'created' => $created,
        'model' => $model,
        'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
    ]));

    sse_done();
}

/**
 * Stream a tool-calls entry as SSE deltas (arguments delivered in multiple
 * chunks to exercise index-preserving merge), then [DONE].
 *
 * @param array<int, array<string, mixed>> $toolCalls
 * @param array<string, mixed>             $request the worker's chat payload
 */
function stream_entry(string $content, array $toolCalls, array $request, array $entry = []): never
{
    http_response_code(200);
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $model = $request['model'] ?? 'mock-model';
    $id = 'chatcmpl-mock-' . time();
    $created = time();

    if ($toolCalls === []) {
        // Carry stall_after through so a content entry can hang mid-stream.
        $fullBody = ['id' => $id, 'created' => $created];
        foreach (['stall_after', 'chunk_delay'] as $passThrough) {
            if (isset($entry[$passThrough])) {
                $fullBody[$passThrough] = $entry[$passThrough];
            }
        }
        stream_content($content, $fullBody, $request);
    }

    foreach ($toolCalls as $index => $call) {
        $fn = $call['function'] ?? [];
        $name = (string) ($fn['name'] ?? '');
        $arguments = (string) ($fn['arguments'] ?? '{}');

        // First frame: identity (id/type/name) + empty arguments.
        sse_data((string) json_encode([
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => $created,
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'delta' => [
                    'tool_calls' => [[
                        'index' => $index,
                        'id' => $call['id'] ?? ('call_' . $index),
                        'type' => 'function',
                        'function' => ['name' => $name, 'arguments' => ''],
                    ]],
                ],
                'finish_reason' => null,
            ]],
        ]));

        // Then the arguments split across chunks (exercise concatenation).
        $pieces = chunk_text($arguments, 4);
        foreach ($pieces as $piece) {
            sse_data((string) json_encode([
                'id' => $id,
                'object' => 'chat.completion.chunk',
                'created' => $created,
                'model' => $model,
                'choices' => [[
                    'index' => 0,
                    'delta' => [
                        'tool_calls' => [[
                            'index' => $index,
                            'function' => ['arguments' => $piece],
                        ]],
                    ],
                    'finish_reason' => null,
                ]],
            ]));
        }
    }

    // Final frame: finish_reason tool_calls + usage, then DONE.
    sse_data((string) json_encode([
        'id' => $id,
        'object' => 'chat.completion.chunk',
        'created' => $created,
        'model' => $model,
        'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
    ]));

    sse_done();
}

/**
 * Split text into roughly equal pieces (min 1 char). Empty string → [""].
 *
 * @return string[]
 */
function chunk_text(string $text, int $chunkSize): array
{
    if ('' === $text) {
        return [''];
    }

    return str_split($text, max(1, $chunkSize));
}
