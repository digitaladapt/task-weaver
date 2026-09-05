<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use App\Entity\Worker;
use App\Repository\EventRepository;
use App\Service\ToolProxyService;
use App\Service\WorkerAuthService;

use function is_array;
use function is_string;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/worker')]
final class InternalToolController extends AbstractController
{
    #[Route('/tool/internal', name: 'worker_tool_internal', methods: ['POST'])]
    public function log(
        Request $request,
        WorkerAuthService $auth,
        EventRepository $events,
        ToolProxyService $proxy,
    ): JsonResponse {
        $worker = $auth->findWorker($request->headers->get('Authorization'));
        if (!$worker instanceof Worker) {
            return $this->json(['error' => 'Invalid worker API key'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode((string) $request->getContent(), true) ?? [];
        $eventId = is_string($payload['event_id'] ?? null) ? $payload['event_id'] : null;
        $toolName = is_string($payload['tool'] ?? null) ? $payload['tool'] : '';

        if (null === $eventId || '' === $toolName) {
            return $this->json(['error' => 'event_id and tool are required'], Response::HTTP_BAD_REQUEST);
        }

        $event = $events->find($eventId);
        if (!$event instanceof Event) {
            return $this->json(['error' => 'Event not found'], Response::HTTP_NOT_FOUND);
        }

        // Only the event's own worker may log internal tools for it.
        if ($event->getWorker()->getId()->toRfc4122() !== $worker->getId()->toRfc4122()) {
            return $this->json(['error' => 'Event belongs to another worker'], Response::HTTP_FORBIDDEN);
        }

        $details = is_array($payload['details'] ?? null) ? $payload['details'] : [];
        $proxy->logInternalToolCall($event, $toolName, $details);

        return $this->json(['ok' => true]);
    }
}
