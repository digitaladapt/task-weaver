<?php

declare(strict_types=1);

namespace TaskWeaverWorker;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal OpenAI-compatible chat client for the worker's local LLM channel.
 *
 * The worker talks to the LLM directly over the private Docker network
 * (SPEC.md Design Principles #2); TaskWeaver never sees the model traffic.
 */
final class LlmClient
{
    private readonly HttpClientInterface $http;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model = 'llama3.1',
    ) {
        $this->http = HttpClient::create(['timeout' => 300]);
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     *
     * @return array{content: string, tool_calls?: array<int, mixed>}
     */
    public function chat(array $messages, array $tools = []): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
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

        $response = $this->http->request('POST', rtrim($this->baseUrl, '/') . '/chat/completions', [
            'json' => $payload,
        ]);

        $status = $response->getStatusCode();
        $content = $response->getContent(false);

        if ($status >= 400) {
            throw new \RuntimeException("LLM error {$status}: " . substr($content, 0, 500));
        }

        $data = json_decode($content, true);
        $choice = $data['choices'][0] ?? null;
        if (!is_array($choice)) {
            throw new \RuntimeException('LLM returned no choices');
        }

        $message = $choice['message'] ?? [];
        $toolCalls = $message['tool_calls'] ?? [];

        return [
            'content' => is_string($message['content'] ?? null) ? $message['content'] : '',
            'tool_calls' => is_array($toolCalls) ? $toolCalls : [],
        ];
    }
}
