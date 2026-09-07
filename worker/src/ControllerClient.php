<?php

declare(strict_types=1);

namespace TaskWeaverWorker;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin HTTP client for the TaskWeaver controller's worker API (Tier-1/2).
 */
final class ControllerClient
{
    private readonly HttpClientInterface $http;
    private ?string $workerKey = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $enrollmentToken,
    ) {
        $this->http = HttpClient::create([
            'timeout' => 30,
        ]);
    }

    /**
     * POST /api/worker/provision — Tier-0 (enrollment token).
     *
     * @return array<string, mixed>
     */
    public function provision(string $name, array $descriptor = []): array
    {
        $response = $this->request('POST', '/api/worker/provision', [
            'headers' => ['Authorization' => 'Bearer ' . $this->enrollmentToken],
            'json' => ['name' => $name, 'descriptor' => $descriptor],
        ]);

        $this->workerKey = $response['api_key'] ?? null;

        return $response;
    }

    public function workerKey(): ?string
    {
        return $this->workerKey;
    }

    /**
     * POST /api/worker/claim — Tier-1.
     *
     * @return array{task: array<string, mixed>|null, step?: array<string, mixed>}
     */
    public function claim(): array
    {
        return $this->request('POST', '/api/worker/claim');
    }

    /**
     * GET /api/worker/task/{taskId} — Tier-1.
     *
     * @return array<string, mixed>
     */
    public function fetchTask(string $taskId): array
    {
        return $this->request('GET', '/api/worker/task/' . urlencode($taskId));
    }

    /**
     * POST /api/worker/event/{taskId}/{stepId} — Tier-1.
     *
     * @return array{event_id: string, api_key: string}
     */
    public function registerEvent(string $taskId, string $stepId): array
    {
        return $this->request('POST', sprintf('/api/worker/event/%s/%s', urlencode($taskId), urlencode($stepId)));
    }

    /**
     * POST /api/worker/tool/{taskId}/{eventId} — Tier-2 (event key).
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public function callTool(string $taskId, string $eventId, string $eventKey, string $tool, array $args, ?string $idempotencyKey): array
    {
        $body = ['tool' => $tool, 'args' => $args];
        if ($idempotencyKey !== null) {
            $body['idempotency_key'] = $idempotencyKey;
        }

        return $this->request('POST', sprintf('/api/worker/tool/%s/%s', urlencode($taskId), urlencode($eventId)), [
            'headers' => ['X-Event-Key' => $eventKey],
            'json' => $body,
        ]);
    }

    /**
     * POST /api/worker/tool/internal — Tier-1 (worker key).
     *
     * Log a worker-executed internal tool call for the audit trail. The
     * controller only records it; the worker itself ran the tool.
     *
     * @param array<string, mixed> $details
     */
    public function logInternalToolCall(string $eventId, string $tool, array $details = []): array
    {
        return $this->request('POST', '/api/worker/tool/internal', [
            'json' => ['event_id' => $eventId, 'tool' => $tool, 'details' => $details],
        ]);
    }

    /**
     * PATCH /api/worker/step/{taskId}/{stepId}/status — Tier-1.
     */
    public function markRunning(string $taskId, string $stepId): array
    {
        return $this->request('PATCH', sprintf('/api/worker/step/%s/%s/status', urlencode($taskId), urlencode($stepId)), [
            'json' => ['status' => 'running'],
        ]);
    }

    /**
     * POST /api/worker/step/{taskId}/{stepId}/complete — Tier-1.
     *
     * @param array<string, mixed> $result
     */
    public function complete(string $taskId, string $stepId, array $result): array
    {
        return $this->request('POST', sprintf('/api/worker/step/%s/%s/complete', urlencode($taskId), urlencode($stepId)), [
            'json' => ['result' => $result],
        ]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     *
     * @throws HttpException on non-2xx, with the status code so the caller can
     *                       implement the abandon-on-denial rule.
     */
    private function request(string $method, string $path, array $options = []): array
    {
        if ($this->workerKey !== null) {
            $options['headers'] ??= [];
            $options['headers']['Authorization'] = 'Bearer ' . $this->workerKey;
        }

        $url = rtrim($this->baseUrl, '/') . $path;

        try {
            $response = $this->http->request($method, $url, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (\Throwable $e) {
            throw new HttpException('Transport error: ' . $e->getMessage(), 0);
        }

        $decoded = json_decode($content, true);

        if ($status < 200 || $status >= 300) {
            throw new HttpException(
                is_array($decoded) ? ($decoded['error'] ?? "HTTP {$status}") : "HTTP {$status}",
                $status,
            );
        }

        if (!is_array($decoded)) {
            throw new HttpException('Unexpected non-JSON response', $status);
        }

        return $decoded;
    }
}
