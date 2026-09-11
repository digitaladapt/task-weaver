<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Worker;
use App\Service\WorkerAuthService;

use function is_array;
use function is_int;
use function is_string;

use const JSON_UNESCAPED_SLASHES;

use function mb_substr;
use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * LLM proxy for keyed/external providers (WORKER.md → The LLM channel).
 *
 * The controller holds the provider key; the worker never does. The worker
 * authenticates with its Tier-1 worker key and POSTs an OpenAI-compatible
 * chat-completions payload; we forward it to the configured upstream with
 * the provider's Authorization header attached, and relay the response back.
 *
 * Only active when TASKWEAVER_LLM_API_KEY is set — for a direct local LLM
 * (no key), the worker talks to the LLM itself and never hits this route.
 *
 * Streaming (STREAMING.md):
 *  - when the worker sends `stream: true`, this route is a **true SSE
 *    relay**: the upstream SSE frames are streamed through verbatim
 *    (headers never forwarded, only model data), so the worker consumes the
 *    deltas exactly as it would from the provider;
 *  - when the worker sends a non-streaming payload (legacy / opt-out), the
 *    old buffered JSON relay behavior is preserved.
 */
#[Route('/api/worker')]
final class LlmProxyController extends AbstractController
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    #[Route('/llm', name: 'worker_llm_proxy', methods: ['POST'])]
    public function proxy(
        Request $request,
        WorkerAuthService $auth,
    ): Response {
        $worker = $auth->findWorker($request->headers->get('Authorization'));
        if (!$worker instanceof Worker) {
            return $this->json(['error' => 'Invalid worker API key'], Response::HTTP_UNAUTHORIZED);
        }

        $apiKey = $this->getParameter('llm_api_key');
        $upstreamUrl = $this->getParameter('llm_proxy_upstream_url');
        if (!is_string($apiKey) || '' === $apiKey) {
            return $this->json(['error' => 'LLM proxy is not configured (no provider key set)'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        // Enforce a cap on requested max_tokens so the worker can't ask the
        // provider for unbounded output (protects the context budget).
        $contextOutputBuffer = (int) $this->getParameter('llm_proxy_output_buffer');
        if (isset($payload['max_tokens']) && is_int($payload['max_tokens']) && $payload['max_tokens'] > $contextOutputBuffer) {
            $payload['max_tokens'] = $contextOutputBuffer;
        }

        if (($payload['stream'] ?? false) === true) {
            return $this->streamProxy($upstreamUrl, $apiKey, $payload);
        }

        return $this->bufferedProxy($upstreamUrl, $apiKey, $payload);
    }

    /**
     * Stream the upstream SSE response straight through to the worker.
     *
     * The worker (LlmClient::postStreaming) parses the frames itself, so the
     * controller must relay the raw bytes — re-encoding them here would
     * corrupt incrementally-delivered tool-call deltas (each frame carries a
     * partial `arguments` string; the worker concatenates them).
     */
    private function streamProxy(string $upstreamUrl, string $apiKey, array $payload): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($upstreamUrl, $apiKey, $payload): void {
            try {
                $upstream = $this->httpClient->request('POST', $upstreamUrl, [
                    'headers' => [
                        'Authorization' => 'Bearer '.$apiKey,
                        'Content-Type' => 'application/json',
                        'Accept' => 'text/event-stream',
                    ],
                    'json' => $payload,
                    'buffer' => false,
                    'timeout' => 300,
                ]);

                $status = $upstream->getStatusCode();
                if ($status >= 400) {
                    $body = $upstream->getContent(false);
                    $this->emitError(sprintf('LLM upstream error %d: %s', $status, mb_substr((string) $body, 0, 500)));

                    return;
                }

                foreach ($this->httpClient->stream($upstream) as $chunk) {
                    if (!$chunk instanceof ChunkInterface) {
                        continue;
                    }

                    if (null !== $chunk->getInformationalStatus()) {
                        continue;
                    }

                    if ($chunk->isTimeout() || $chunk->isLast()) {
                        if ($chunk->isTimeout()) {
                            $this->emitError('LLM upstream connection timed out while streaming');
                        }

                        return;
                    }

                    $data = $chunk->getContent();
                    if ('' !== $data) {
                        echo $data;
                        @ob_flush();
                        flush();
                    }
                }
            } catch (Throwable $e) {
                $this->emitError('LLM upstream unreachable while streaming: '.$e->getMessage());
            }
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);

        return $response;
    }

    /**
     * Buffered relay — the v1 behavior, kept for non-streaming callers.
     */
    private function bufferedProxy(string $upstreamUrl, string $apiKey, array $payload): JsonResponse
    {
        try {
            $response = $this->httpClient->request('POST', $upstreamUrl, [
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 300,
            ]);

            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (Throwable $e) {
            return $this->json(['error' => 'LLM upstream unreachable: '.$e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        if ($status >= 400) {
            return $this->json([
                'error' => sprintf('LLM upstream error %d: %s', $status, mb_substr((string) $content, 0, 500)),
            ], Response::HTTP_BAD_GATEWAY);
        }

        // Pass the upstream JSON through untouched.
        return new JsonResponse($content, 200, [], true);
    }

    /**
     * Emit a well-formed SSE error frame so the worker's parser sees a
     * terminal `event: error` rather than a dead connection.
     */
    private function emitError(string $message): void
    {
        echo "event: error\n";
        echo 'data: '.json_encode(['error' => $message], JSON_UNESCAPED_SLASHES)."\n\n";
        @ob_flush();
        flush();
    }
}
