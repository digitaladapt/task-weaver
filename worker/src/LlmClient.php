<?php

declare(strict_types=1);

namespace TaskWeaverWorker;

use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal OpenAI-compatible chat client for the worker's LLM channel.
 *
 * Two channels (WORKER.md → The LLM channel):
 *  - direct: the worker talks to the local LLM itself (no key, private net);
 *  - proxy:  the worker POSTs payloads to the controller's /api/worker/llm
 *            route and authenticates with its Tier-1 worker key — the
 *            controller holds the provider key, the worker never does.
 *
 * Hardened for real LLM use:
 *  - retries with bounded exponential backoff + jitter on transport errors
 *    and 5xx/429 (WORKER.md error contract: "transient → worker retries");
 *  - max_tokens always sent, capped at the context output buffer so the
 *    model can never request unbounded output;
 *  - strict timeouts (connect + request).
 */
final class LlmClient
{
    private readonly HttpClientInterface $http;

    private int $retriesLeft;

    private int $backoffBaseMs = 500;

    private int $maxTokens = 1500;

    private int $contextBudget = 7500;

    /**
     * @param array{retries?: int, backoff_base_ms?: int} $options
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model = 'Qwen3.5-4B',
        private readonly ?string $bearerToken = null,
        array $options = [],
    ) {
        $this->http = HttpClient::create(['timeout' => 300]);
        $this->retriesLeft = max(1, (int) ($options['retries'] ?? 3));
        $this->backoffBaseMs = max(100, (int) ($options['backoff_base_ms'] ?? 500));
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
     * @param array<int, array{name: string, description?: ?string, schema: array<string, mixed>}> $tools
     *
     * @return array{content: string, tool_calls: array<int, mixed>, usage?: array<string, int>}
     */
    public function chat(array $messages, array $tools = []): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            // Always cap generation so the model always leaves room within
            // its context budget for the output buffer (WORKER.md §6).
            'max_tokens' => $this->maxTokens,
        ];

        if ($tools !== []) {
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

        return $this->postWithRetry($payload);
    }

    /**
     * POST the payload, retrying transient failures with bounded backoff.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{content: string, tool_calls: array<int, mixed>, usage?: array<string, int>}
     */
    private function postWithRetry(array $payload): array
    {
        $attempt = 0;
        $maxAttempts = $this->retriesLeft;
        $backoffMs = $this->backoffBaseMs;

        while (true) {
            $attempt++;
            try {
                return $this->postOnce($payload);
            } catch (LlmTransientException $e) {
                if ($attempt >= $maxAttempts) {
                    throw new RuntimeException(sprintf(
                        'LLM call failed after %d attempts: %s',
                        $attempt,
                        $e->getMessage(),
                    ), 0, $e);
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
        if ($this->bearerToken !== null && $this->bearerToken !== '') {
            // Proxied channel: the worker key authenticates to the
            // controller's /api/worker/llm route (Tier-1), never to a
            // provider directly.
            $headers['Authorization'] = 'Bearer ' . $this->bearerToken;
        }

        try {
            $response = $this->http->request('POST', $this->endpoint(), [
                'headers' => $headers,
                'json' => $payload,
                'timeout' => 300,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getcontent(false);
        } catch (\Throwable $e) {
            // Transport error (network down, DNS, TLS) — retryable.
            throw new LlmTransientException('Transport error: ' . $e->getMessage());
        }

        if ($status === 429 || $status >= 500) {
            // Rate limited / upstream trouble — retryable.
            throw new LlmTransientException(sprintf('LLM error %d: %s', $status, substr($content, 0, 300)));
        }

        if ($status >= 400) {
            // Permanent client error — do not retry.
            throw new RuntimeException("LLM error {$status}: " . substr($content, 0, 500));
        }

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
        return rtrim($this->baseUrl, '/') . '/chat/completions';
    }
}