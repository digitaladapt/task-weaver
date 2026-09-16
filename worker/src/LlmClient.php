<?php

declare(strict_types=1);

namespace TaskWeaverWorker;

use function is_array;
use function is_callable;
use function is_string;

use const JSON_THROW_ON_ERROR;

use function random_int;

use RuntimeException;

use function sprintf;
use function str_replace;
use function strlen;
use function substr;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function usleep;

/**
 * Minimal OpenAI-compatible chat client for the worker's LLM channel.
 *
 * Two channels (WORKER.md → The LLM channel):
 *  - direct: the worker talks to the local LLM itself (no key, private net);
 *    baseUrl is the LLM base (e.g. http://llm:8080/v1) and /chat/completions
 *    is appended.
 *  - proxy:  the worker POSTs payloads to the controller's /api/worker/llm
 *            route and authenticates with its Tier-1 worker key — the
 *            controller holds the provider key, the worker never does.
 *            baseUrl is the controller-issued FULL endpoint, so pass
 *            ['full_endpoint' => true] to skip the /chat/completions suffix.
 *
 * Streaming (STREAMING.md):
 *  - every chat() is streamed by default (payload `stream: true`);
 *  - SSE `delta.content` chunks are assembled into the final content;
 *  - `delta.tool_calls[]` fragments are merged by index (id/type/name first
 *    occurrence, function.arguments concatenated) into the same array shape
 *    the non-streaming endpoint returns — the tool loop is unchanged;
 *  - a final `usage` chunk (if the provider sends one) is captured;
 *  - if an upstream ignores `stream: true` and answers with a plain JSON
 *    body instead of SSE frames, the client parses it as a normal
 *    chat-completions response (graceful fallback);
 *  - transient failures mid-stream (transport errors, 5xx, 429) are retried
 *    with bounded exponential backoff + jitter, and the response is
 *    cancelled so no zombie connection keeps streaming.
 *
 * Hardened for real LLM use:
 *  - max_tokens always sent, capped at the context output buffer so the
 *    model can never request unbounded output;
 *  - strict timeouts (connect + request).
 */
final class LlmClient
{
    private readonly HttpClientInterface $http;

    private string $model;

    private int $retriesLeft;

    private int $backoffBaseMs = 500;

    private int $maxTokens = 1500;

    private int $contextBudget = 7500;

    private bool $fullEndpoint = false;

    /** @var callable(int): void|null */
    private $onChunk;

    /**
     * @param array{retries?: int, backoff_base_ms?: int, full_endpoint?: bool} $options
     */
    public function __construct(
        private readonly string $baseUrl,
        string $model = 'Qwen3.5-4B',
        private readonly ?string $bearerToken = null,
        array $options = [],
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create(['timeout' => 300]);
        $this->model = '' !== $model ? $model : 'Qwen3.5-4B';
        $this->retriesLeft = max(1, (int) ($options['retries'] ?? 3));
        $this->backoffBaseMs = max(100, (int) ($options['backoff_base_ms'] ?? 500));
        // Proxy channel: the controller-issued URL is ALREADY the full
        // endpoint (it accepts the whole chat payload, no /chat/completions
        // suffix). Direct channel: the URL is a base (e.g. .../v1) and the
        // client appends /chat/completions.
        $this->fullEndpoint = (bool) ($options['full_endpoint'] ?? false);
    }

    /**
     * Per-step model selection (docs/model-selection-plan.md §5): switch the
     * model this client sends in every chat payload. The client (connection,
     * auth, budget) is reused — only the model field changes.
     */
    public function setModel(string $model): void
    {
        if ('' !== $model) {
            $this->model = $model;
        }
    }

    /**
     * Configure context-budget caps used for every call.
     */
    public function setContextBudget(int $maxTokens, int $contextBudget): void
    {
        $this->maxTokens = max(1, $maxTokens);
        $this->contextBudget = max(0, $contextBudget);
    }

    /**
     * @param array<int, array{role: string, content: string, tool_call_id?: string, tool_calls?: array<int, mixed>}> $messages
     * @param array<int, array{name: string, description?: ?string, schema: array<string, mixed>}>                    $tools
     * @param array{stream?: bool, on_chunk?: callable(int): void}                                                    $options  on_chunk reports streamed chars for progress logging
     *
     * @return array{content: string, tool_calls: array<int, mixed>, usage?: array<string, int>}
     */
    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        $stream = (bool) ($options['stream'] ?? true);
        $onChunk = isset($options['on_chunk']) && is_callable($options['on_chunk']) ? $options['on_chunk'] : null;

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            // Always cap generation so the model always leaves room within
            // its context budget for the output buffer (WORKER.md §6).
            'max_tokens' => $this->maxTokens,
        ];

        if ($stream) {
            $payload['stream'] = true;
        }

        if ([] !== $tools) {
            // OpenAI-compatible tool calling format.
            $payload['tools'] = array_map(static function (array $tool): array {
                return [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool['name'],
                        'description' => $tool['description'] ?? '',
                        'parameters' => $tool['schema'] ?? ['type' => 'object'],
                    ],
                ];
            }, $tools);
        }

        $this->onChunk = $onChunk;

        return $this->postWithRetry($payload, $stream);
    }

    /**
     * POST the payload, retrying transient failures with bounded backoff.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{content: string, tool_calls: array<int, mixed>, usage?: array<string, int>}
     */
    private function postWithRetry(array $payload, bool $stream): array
    {
        $attempt = 0;
        $maxAttempts = $this->retriesLeft;
        $backoffMs = $this->backoffBaseMs;

        while (true) {
            ++$attempt;
            try {
                return $stream
                    ? $this->postStreaming($payload)
                    : $this->postOnce($payload);
            } catch (LlmTransientException $e) {
                if ($attempt >= $maxAttempts) {
                    throw new RuntimeException(sprintf('LLM call failed after %d attempts: %s', $attempt, $e->getMessage()), 0, $e);
                }
                usleep($backoffMs * 1000 + random_int(0, $backoffMs));
                $backoffMs = min($backoffMs * 2, 10_000);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{content: string, tool_calls: array<int, mixed>, usage?: array<string, int>}
     *
     * @throws LlmTransientException on transport errors / 5xx / 429 (retryable)
     * @throws RuntimeException      on permanent failures (bad request, malformed response)
     */
    private function postOnce(array $payload): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if (null !== $this->bearerToken && '' !== $this->bearerToken) {
            // Proxied channel: the worker key authenticates to the
            // controller's /api/worker/llm route (Tier-1), never to a
            // provider directly.
            $headers['Authorization'] = 'Bearer '.$this->bearerToken;
        }

        try {
            $response = $this->http->request('POST', $this->endpoint(), [
                'headers' => $headers,
                'json' => $payload,
                'timeout' => 300,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (Throwable $e) {
            // Transport error (network down, DNS, TLS) — retryable.
            throw new LlmTransientException('Transport error: '.$e->getMessage());
        }

        if (429 === $status || $status >= 500) {
            // Rate limited / upstream trouble — retryable.
            throw new LlmTransientException(sprintf('LLM error %d: %s', $status, substr($content, 0, 300)));
        }

        if ($status >= 400) {
            // Permanent client error — do not retry.
            throw new RuntimeException("LLM error {$status}: ".substr($content, 0, 500));
        }

        return $this->normalizeResponse($content);
    }

    /**
     * Stream the chat response (SSE) and assemble the final message.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{content: string, tool_calls: array<int, mixed>, usage?: array<string, int>}
     *
     * @throws LlmTransientException on transport errors / 5xx / 429 (retryable)
     * @throws RuntimeException      on permanent failures (bad request, malformed response)
     */
    private function postStreaming(array $payload): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if (null !== $this->bearerToken && '' !== $this->bearerToken) {
            $headers['Authorization'] = 'Bearer '.$this->bearerToken;
        }

        $response = $this->http->request('POST', $this->endpoint(), [
            'headers' => $headers,
            'json' => $payload,
            'buffer' => false,
            'timeout' => 300,
        ]);

        $content = '';
        $rawBody = '';
        $toolCallDeltas = [];  // keyed by tool-call index
        $usage = [];
        $finishReason = '';
        $status = 0;
        $sawDataFrame = false;
        $errorBody = '';
        $sseBuffer = '';
        $streamError = '';

        try {
            foreach ($this->http->stream($response) as $chunk) {
                if (!$chunk instanceof ChunkInterface) {
                    continue;
                }

                if (null !== $chunk->getInformationalStatus()) {
                    // 1xx informational — ignore.
                    continue;
                }

                if ($chunk->isFirst()) {
                    $status = $response->getStatusCode();
                    if (0 === $status) {
                        // Mock responses can report 0 until initialized;
                        // trust the next chunk.
                        $status = 200;
                    }
                    continue;
                }

                if ($chunk->isLast()) {
                    break;
                }

                $data = $chunk->getContent();
                if ('' === $data) {
                    continue;
                }

                if ($status >= 400) {
                    $errorBody .= $data;
                    continue;
                }

                $rawBody .= $data;
                $sseBuffer .= str_replace("\r\n", "\n", $data);

                while (false !== ($sep = strpos($sseBuffer, "\n\n"))) {
                    $frame = substr($sseBuffer, 0, $sep);
                    $sseBuffer = substr($sseBuffer, $sep + 2);
                    $this->consumeSseFrame($frame, $content, $toolCallDeltas, $usage, $finishReason, $sawDataFrame, $streamError);
                }
            }
        } catch (Throwable $e) {
            // Transport error mid-stream — cancel and retry.
            $response->cancel();

            throw new LlmTransientException('Transport error: '.$e->getMessage());
        }

        // Upstream answered with a plain JSON body (no SSE frames) even
        // though we asked for streaming — parse it like a normal response.
        if (!$sawDataFrame && '' !== trim($rawBody)) {
            try {
                $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw new LlmTransientException('LLM returned malformed stream body');
            }

            if (is_array($decoded) && isset($decoded['choices'])) {
                return $this->normalizeResponse($rawBody);
            }
        }

        // Flush any trailing SSE frame that had no final blank line.
        if ('' !== $sseBuffer) {
            $this->consumeSseFrame($sseBuffer, $content, $toolCallDeltas, $usage, $finishReason, $sawDataFrame, $streamError);
        }

        if ('' !== $streamError) {
            throw new RuntimeException($streamError);
        }

        if (429 === $status || $status >= 500) {
            throw new LlmTransientException(sprintf('LLM error %d: %s', $status, substr($errorBody, 0, 300)));
        }

        if ($status >= 400) {
            throw new RuntimeException("LLM error {$status}: ".substr($errorBody, 0, 500));
        }

        return [
            'content' => $content,
            'tool_calls' => $this->assembleToolCalls($toolCallDeltas),
            'usage' => $usage,
        ];
    }

    /**
     * Consume one SSE frame (one or more `data:` lines).
     *
     * @param array<int, mixed> $toolCallDeltas keyed by index, mutated in place
     */
    private function consumeSseFrame(
        string $frame,
        string &$content,
        array &$toolCallDeltas,
        array &$usage,
        string &$finishReason,
        bool &$sawDataFrame,
        string &$streamError,
    ): void {
        // Controller-issued error frames (event: error + data: {error}).
        if (preg_match('/^event:\s*error$/m', $frame)) {
            foreach (explode("\n", $frame) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $json = json_decode(ltrim(substr($line, 5)), true);
                    if (is_array($json) && is_string($json['error'] ?? null)) {
                        $streamError = $json['error'];
                    }
                }
            }
            $sawDataFrame = true;

            return;
        }
        $dataLines = [];
        foreach (explode("\n", $frame) as $line) {
            if (str_starts_with($line, 'data:')) {
                $sawDataFrame = true;
                $dataLines[] = ltrim(substr($line, 5));
            }
        }

        if ([] === $dataLines) {
            return;
        }

        $data = implode("\n", $dataLines);
        if ('[DONE]' === $data) {
            return;
        }

        $json = json_decode($data, true);
        if (!is_array($json)) {
            // Partial/keepalive frame — ignore.
            return;
        }

        if (is_array($json['usage'] ?? null)) {
            $usage = $json['usage'];
        }

        foreach (($json['choices'] ?? []) as $choice) {
            if (!is_array($choice)) {
                continue;
            }

            $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
            if (is_string($delta['content'] ?? null)) {
                $content .= $delta['content'];
                if (null !== $this->onChunk) {
                    ($this->onChunk)(strlen($content));
                }
            }

            foreach (($delta['tool_calls'] ?? []) as $toolCall) {
                if (!is_array($toolCall)) {
                    continue;
                }
                $index = (int) ($toolCall['index'] ?? 0);
                $toolCallDeltas[$index] ??= [
                    'id' => '',
                    'type' => 'function',
                    'function' => ['name' => '', 'arguments' => ''],
                ];
                if (is_string($toolCall['id'] ?? null)) {
                    $toolCallDeltas[$index]['id'] = $toolCall['id'];
                }
                if (is_string($toolCall['type'] ?? null)) {
                    $toolCallDeltas[$index]['type'] = $toolCall['type'];
                }
                $fn = is_array($toolCall['function'] ?? null) ? $toolCall['function'] : [];
                if (is_string($fn['name'] ?? null)) {
                    $toolCallDeltas[$index]['function']['name'] = $fn['name'];
                }
                if (is_string($fn['arguments'] ?? null)) {
                    $toolCallDeltas[$index]['function']['arguments'] .= $fn['arguments'];
                }
            }

            if (is_string($choice['finish_reason'] ?? null)) {
                $finishReason = $choice['finish_reason'];
            }
        }
    }

    /**
     * @param array<int, mixed> $toolCallDeltas
     *
     * @return array<int, array<string, mixed>>
     */
    private function assembleToolCalls(array $toolCallDeltas): array
    {
        ksort($toolCallDeltas);

        $toolCalls = [];
        foreach ($toolCallDeltas as $delta) {
            if (!is_array($delta)) {
                continue;
            }
            $fn = is_array($delta['function'] ?? null) ? $delta['function'] : [];
            if ('' === (string) ($delta['id'] ?? '')
                && '' === (string) ($fn['name'] ?? '')
                && '' === (string) ($fn['arguments'] ?? '')) {
                continue;
            }
            $toolCalls[] = [
                'id' => $delta['id'],
                'type' => $delta['type'],
                'function' => [
                    'name' => $fn['name'] ?? '',
                    'arguments' => $fn['arguments'] ?? '',
                ],
            ];
        }

        return $toolCalls;
    }

    /**
     * @return array{content: string, tool_calls: array<int, mixed>, usage?: array<string, int>}
     *
     * @throws LlmTransientException when the body is malformed (provider flake)
     */
    private function normalizeResponse(string $content): array
    {
        $data = json_decode($content, true);
        $choice = $data['choices'][0] ?? null;
        if (!is_array($choice)) {
            // Malformed response — treat as transient (some providers flake).
            throw new LlmTransientException('LLM returned no choices');
        }

        $message = $choice['message'] ?? [];
        $toolCalls = $message['tool_calls'] ?? [];

        return [
            'content' => is_string($message['content'] ?? null) ? $message['content'] : '',
            'tool_calls' => is_array($toolCalls) ? $toolCalls : [],
            'usage' => is_array($data['usage'] ?? null) ? $data['usage'] : [],
        ];
    }

    private function endpoint(): string
    {
        if ($this->fullEndpoint) {
            // Controller-issued proxy URL is already the full endpoint.
            return $this->baseUrl;
        }

        return rtrim($this->baseUrl, '/').'/chat/completions';
    }
}
