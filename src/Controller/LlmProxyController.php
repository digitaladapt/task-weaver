<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Worker;
use App\Service\WorkerAuthService;

use function is_array;
use function is_int;
use function is_string;
use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * LLM proxy for keyed/external providers (WORKER.md → The LLM channel).
 *
 * The controller holds the provider key; the worker never does. The worker
 * authenticates with its Tier-1 worker key and POSTs an OpenAI-compatible
 * chat-completions payload; we forward it to the configured upstream with
 * the provider's Authorization header attached, and stream the JSON back.
 *
 * Only active when TASKWEAVER_LLM_API_KEY is set — for a direct local LLM
 * (no key), the worker talks to the LLM itself and never hits this route.
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
    ): JsonResponse {
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
}
