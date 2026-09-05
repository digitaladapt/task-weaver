<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ProvisionService;
use InvalidArgumentException;

use function is_array;
use function is_string;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/worker')]
final class ProvisionController extends AbstractController
{
    #[Route('/provision', name: 'worker_provision', methods: ['POST'])]
    public function provision(Request $request, ProvisionService $provision): JsonResponse
    {
        $auth = $request->headers->get('Authorization');
        $token = null !== $auth && preg_match('/^Bearer\s+(.+)$/i', $auth, $m) ? trim($m[1]) : null;

        if (null === $token || '' === $token) {
            return $this->json(['error' => 'Missing enrollment token'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode((string) $request->getContent(), true) ?? [];
        $name = is_string($payload['name'] ?? null) ? $payload['name'] : 'worker';

        try {
            $result = $provision->provision($token, $name, is_array($payload['descriptor'] ?? null) ? $payload['descriptor'] : []);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json($result);
    }
}
