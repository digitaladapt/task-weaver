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
    respond(200, ['id' => 'chatcmpl-mock-echo', 'object' => 'chat.completion', 'created' => time(), 'model' => $body['model'] ?? 'mock-model', 'choices' => [['index' => 0, 'message' => ['content' => $lastUser], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120]]);
}

$toolCalls = $entry['tool_calls'] ?? [];
$content = $entry['content'] ?? '';

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