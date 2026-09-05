<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use App\Repository\EventRepository;
use App\Service\ToolProxyService;
use DateTimeImmutable;

use function is_array;
use function is_string;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/worker')]
final class ToolController extends AbstractController
{
    #[Route('/tool/{taskId}/{eventId}', name: 'worker_tool_call', methods: ['POST'])]
    public function call(
        string $taskId,
        string $eventId,
        Request $request,
        EventRepository $events,
        ToolProxyService $proxy,
    ): JsonResponse {
        // Tier-2 event key (X-Event-Key). This is the event-scoped credential.
        $eventKey = $request->headers->get('X-Event-Key');
        if (null === $eventKey || '' === $eventKey) {
            return $this->json(['error' => 'Missing X-Event-Key'], Response::HTTP_UNAUTHORIZED);
        }

        $event = $events->findByApiKey($eventKey);
        if (!$event instanceof Event) {
            // Unknown/revoked/expired key → 401 (worker abandons the step).
            return $this->json(['error' => 'Invalid event key'], Response::HTTP_UNAUTHORIZED);
        }

        // Event must belong to the listed task.
        if ($event->getTask()->getId()->toRfc4122() !== $taskId) {
            return $this->json(['error' => 'Event does not belong to this task'], Response::HTTP_UNAUTHORIZED);
        }

        // Event key must still be valid (step running + within deadline).
        if (!$event->hasValidKey(new DateTimeImmutable())) {
            return $this->json(['error' => 'Event key expired'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode((string) $request->getContent(), true) ?? [];
        $toolName = is_string($payload['tool'] ?? null) ? $payload['tool'] : '';
        $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];
        $idem = is_string($payload['idempotency_key'] ?? null) ? $payload['idempotency_key'] : null;

        if ('' === $toolName) {
            return $this->json(['error' => 'Missing "tool" in request body'], Response::HTTP_BAD_REQUEST);
        }

        $result = $proxy->handleToolCall($event, $toolName, $args, $idem);

        if (($result['ok'] ?? false) === false && ($result['status'] ?? '') === 'denied') {
            return $this->json($result, Response::HTTP_FORBIDDEN);
        }

        return $this->json($result);
    }
}
