<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Worker;
use App\Service\ClaimService;
use App\Service\WorkerAuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/worker')]
final class ClaimController extends AbstractController
{
    #[Route('/claim', name: 'worker_claim', methods: ['POST'])]
    public function claim(Request $request, WorkerAuthService $auth, ClaimService $claims): JsonResponse
    {
        $worker = $auth->findWorker($request->headers->get('Authorization'));

        if (!$worker instanceof Worker) {
            return $this->json(['error' => 'Invalid worker API key'], Response::HTTP_UNAUTHORIZED);
        }

        $worker->markSeen();
        $result = $claims->claimFor($worker);

        if (null === $result) {
            return $this->json(['task' => null]);
        }

        // (Reply-run claim → message `running` transition happens inside
        // ClaimService::claimFor — claims are also made from tests/CLI.)

        return $this->json([
            'task' => [
                'id' => $result['task']->getId()->toRfc4122(),
                'priority' => $result['task']->getPriority(),
            ],
            'step' => [
                'id' => $result['step']->getId()->toRfc4122(),
            ],
        ]);
    }
}
