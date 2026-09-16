<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Worker;
use App\Service\ModelCatalogService;
use App\Service\WorkerAuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * LLM model discovery (docs/model-selection-plan.md §4.3).
 *
 * Two routes, one normalized payload `{models: [...], default: "..."}`:
 *  - GET /api/models            — admin session auth; UI datalist source +
 *                                 operator REST endpoint; `?refresh=1`
 *                                 bypasses the in-process cache.
 *  - GET /api/worker/llm/models — Tier-1 worker key; worker-side discovery
 *                                 (parity with the chat proxy route).
 *
 * Both degrade to the default-only list when the upstream is unreachable —
 * discovery must never take chat traffic down (M3c).
 */
final class ModelController extends AbstractController
{
    #[Route('/api/models', name: 'app_models', methods: ['GET'])]
    public function admin(Request $request, ModelCatalogService $catalog): JsonResponse
    {
        $refresh = '1' === $request->query->get('refresh');

        return $this->json($catalog->list($refresh));
    }

    #[Route('/api/worker/llm/models', name: 'worker_llm_models', methods: ['GET'])]
    public function worker(Request $request, WorkerAuthService $auth, ModelCatalogService $catalog): JsonResponse
    {
        $worker = $auth->findWorker($request->headers->get('Authorization'));
        if (!$worker instanceof Worker) {
            return $this->json(['error' => 'Invalid worker API key'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json($catalog->list());
    }
}
