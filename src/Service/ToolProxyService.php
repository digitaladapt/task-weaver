<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Entity\Step;
use App\Entity\ToolCall;
use App\MCP\McpClientRegistry;
use Doctrine\ORM\EntityManagerInterface;

use function is_array;

use Psr\Log\LoggerInterface;

/**
 * The Tool Proxy — TaskWeaver's core.
 *
 * Validates an event key, verifies the tool is allowed for the step (tags),
 * executes the call via the registered MCP clients, records it as a ToolCall,
 * and returns the result to the worker. Handles idempotency and secret
 * containment (never forwards secrets, never reflects them back).
 */
final class ToolProxyService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly McpClientRegistry $mcpRegistry,
        private readonly ToolResolver $toolResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Execute an external tool call.
     *
     * @param array<string, mixed> $arguments
     * @param string|null          $idempotencyKey client-supplied dedupe key
     *
     * @return array{ok: bool, tool_call_id?: string, result?: array<string, mixed>, error?: string, status?: string}
     */
    public function handleToolCall(Event $event, string $toolName, array $arguments, ?string $idempotencyKey = null): array
    {
        $step = $event->getStep();

        // --- Authorization: this step must be allowed to call this tool ---
        try {
            $toolDef = $this->toolResolver->resolveAllowed($step, $toolName);
        } catch (ToolNotAllowedException $e) {
            $this->logger->warning('Tool call denied', [
                'tool' => $toolName,
                'step' => $step->getId()->toRfc4122(),
                'reason' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => $e->getMessage(), 'status' => 'denied'];
        }

        // --- Idempotency: a retried timed-out call must not double-fire ---
        $toolCallRepo = $this->em->getRepository(ToolCall::class);
        if (null !== $idempotencyKey && '' !== $idempotencyKey) {
            $existing = $toolCallRepo->findByIdempotencyKey($event->getId()->toRfc4122(), $idempotencyKey);
            if (null !== $existing) {
                $this->logger->info('Deduped tool call via idempotency key', [
                    'tool_call' => $existing->getId()->toRfc4122(),
                ]);

                return $this->formatResult($existing);
            }
        }

        // --- Log request event ---
        $this->logEvent(Event::TYPE_TOOL_REQUESTED, $event, [
            'tool' => $toolName,
            'args' => $arguments,
            'idempotency_key' => $idempotencyKey,
        ]);

        // --- Execute via MCP registry ---
        $result = $this->mcpRegistry->call($toolDef->getServer(), $toolDef, $arguments);

        $toolCall = new ToolCall($event, $toolName, $arguments);
        $toolCall->setIdempotencyKey($idempotencyKey);
        $toolCall->setStatus($result->ok ? 'completed' : 'failed');

        if ($result->ok) {
            $toolCall->setResponse(is_array($result->data) ? $result->data : ['result' => $result->data]);
        } else {
            $toolCall->setError($result->error);
            $toolCall->setResponse(null);
        }

        $event->addToolCall($toolCall);
        $this->em->persist($toolCall);
        $this->em->flush();

        // --- Log finished event ---
        // Always carries the tool result (nested as a structured value, never
        // a JSON-encoded string), so the timeline shows the full response;
        // null on failure so the shape is consistent.
        $this->logEvent(Event::TYPE_TOOL_FINISHED, $event, [
            'tool' => $toolName,
            'ok' => $result->ok,
            'result' => $toolCall->getResponse(),
            'error' => $result->error,
        ]);

        // --- Return only tool data; never credentials ---
        return [
            'ok' => $result->ok,
            'tool_call_id' => $toolCall->getId()->toRfc4122(),
            ...($result->ok
                ? ['result' => (array) $result->data]
                : ['error' => $result->error, 'status' => 'failed']),
        ];
    }

    /**
     * Log an internal tool call executed by the worker itself.
     *
     * @param array<string, mixed> $payload
     */
    public function logInternalToolCall(Event $event, string $toolName, array $payload): void
    {
        $step = $event->getStep();

        $this->logEvent(Event::TYPE_TOOL_INTERNAL, $event, [
            'tool' => $toolName,
            ...$payload,
        ]);

        $this->logger->info('Internal tool call logged', [
            'tool' => $toolName,
            'step' => $step->getId()->toRfc4122(),
        ]);
    }

    /**
     * @return array{ok: bool, tool_call_id: string, result?: array<string, mixed>, error?: string, status?: string}
     */
    private function formatResult(ToolCall $toolCall): array
    {
        if (null !== $toolCall->getResponse()) {
            return [
                'ok' => true,
                'tool_call_id' => $toolCall->getId()->toRfc4122(),
                'result' => $toolCall->getResponse(),
            ];
        }

        return [
            'ok' => false,
            'tool_call_id' => $toolCall->getId()->toRfc4122(),
            'error' => $toolCall->getError() ?? 'unknown error',
            'status' => 'failed',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function logEvent(string $type, Event $event, array $payload): void
    {
        // A dedicated event per observation keeps the audit trail complete
        // without bloating the worker-facing response.
        $logEvent = new Event($event->getStep(), $event->getTask(), $event->getWorker(), $type);
        $logEvent->setPayload($payload);
        $logEvent->setRunId($event->getStep()->getRunId());
        $this->em->persist($logEvent);
        $this->em->flush();
    }
}
