<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Conversation;
use App\Entity\Event;
use App\Entity\Message;
use App\Entity\MessageToolLog;
use App\Entity\Step;
use App\Entity\Task;
use App\Entity\ToolCall;
use App\Repository\MessageRepository;

use function array_slice;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

use function in_array;
use function is_array;
use function is_string;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use LogicException;
use Psr\Log\LoggerInterface;

use function sprintf;
use function strlen;
use function trim;

/**
 * Conversations & follow-ups (docs/conversations-plan.md).
 *
 * A reply runs as a TRANSIENT one-step task driving the existing worker
 * pipeline. When the run reaches a terminal state the reply is materialized
 * (assistant message + message_tool_log rows copied from the run's events)
 * and the reply task is hard-deleted — nothing lingers in task/step/event/
 * tool_call. The durable record is message + message_tool_log.
 */
final class ConversationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageRepository $messages,
        private readonly TimezoneService $timezone,
        private readonly LoggerInterface $logger,
        private readonly int $historyMessages = 20,
        private readonly int $historyChars = 8000,
    ) {
    }

    /**
     * Create a conversation seeded from a step_* event (follow-up, D2/D3).
     *
     * @throws LogicException when the event is not a step_* type
     */
    public function followUp(Event $event): Conversation
    {
        $stepTypes = [Event::TYPE_STEP_STARTED, Event::TYPE_STEP_COMPLETED, Event::TYPE_STEP_FAILED];
        if (!in_array($event->getType(), $stepTypes, true)) {
            throw new LogicException(sprintf('Follow-up is only available on step_* events (got %s).', $event->getType()));
        }

        $step = $event->getStep();
        $task = $event->getTask();

        $conversation = new Conversation(sprintf(
            'Follow up to %s · %s · %s',
            $task->getName(),
            $step->getName(),
            $event->getType()
        ));
        $conversation->setSourceRunId($event->getRunId());
        $conversation->setSourceEventId($event->getId());

        $seed = new Message($conversation, Message::ROLE_USER, $this->seedContent($event));
        $seed->setIsSeed(true);
        $seed->setSourceEventId($event->getId());
        $seed->setTags($step->getTags());
        $seed->markCompleted();

        $conversation->addMessage($seed);
        $this->em->persist($conversation);
        $this->em->persist($seed);
        $this->em->flush();

        $this->logger->info('Conversation created from follow-up', [
            'conversation' => $conversation->getId()->toRfc4122(),
            'event' => $event->getId()->toRfc4122(),
        ]);

        return $conversation;
    }

    /**
     * Post a user message and ensure a reply run exists for it.
     */
    public function postMessage(Conversation $conversation, string $content, array $tags = []): Message
    {
        $message = new Message($conversation, Message::ROLE_USER, trim($content));
        $message->setTags($tags);
        $conversation->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        $this->ensureReplyRun($conversation);

        return $message;
    }

    /**
     * Ensure a transient reply run exists for a conversation's oldest queued
     * message (one live reply task per conversation — unique conversation_id).
     */
    public function ensureReplyRun(Conversation $conversation): ?Task
    {
        $message = $this->messages->findNextQueued($conversation->getId());
        if (null === $message) {
            return null;
        }

        $existing = $this->em->getRepository(Task::class)->findOneBy(['conversationId' => $conversation->getId()]);
        if (null !== $existing) {
            return $existing;
        }

        return $this->createReplyRun($conversation, $message);
    }

    /**
     * Safety net: create reply runs for every conversation that has a pending
     * message but no live reply task (covers a crash between post and create).
     * Called at claim time (pass 1) and after run completion.
     */
    public function ensureReplyRuns(): void
    {
        $repo = $this->em->getRepository(Conversation::class);
        foreach ($repo->findWithPendingMessage() as $conversation) {
            $this->ensureReplyRun($conversation);
        }
    }

    /**
     * Claim-time status transition (docs/conversations-plan.md §5.4):
     * claim → message `running`. Also marks the reply message as running so
     * the UI shows it live and onStepTerminal can attach the outcome to it.
     */
    public function onClaimed(Task $task): void
    {
        if (!$task->isReplyTask()) {
            return;
        }

        $pending = $this->messages->findPendingByConversation($task->getConversationId());
        $message = $pending[0] ?? null;
        if (null === $message) {
            return;
        }

        if (Message::STATUS_QUEUED === $message->getStatus()) {
            $message->markRunning();
            $this->em->persist($message);
            $this->em->flush();
        }
    }

    /**
     * Called when a reply task's step reaches a terminal state (completed or
     * failed). Materializes the reply, copies tool executions into
     * message_tool_log, then HARD-DELETES the reply task (D10).
     *
     * Idempotent: no-op when the task is not a reply task, or when the
     * materialization already happened (sweep/controller-crash recovery).
     */
    public function onStepTerminal(Task $task, Step $step, bool $failed = false, string $reason = ''): void
    {
        if (!$task->isReplyTask()) {
            return;
        }

        $conversationId = $task->getConversationId();
        $conversation = $this->em->getRepository(Conversation::class)->find($conversationId);
        if (null === $conversation) {
            // Conversation gone; just remove the orphan run.
            $this->em->remove($task);
            $this->em->flush();

            return;
        }

        $message = $this->messages->findNextQueued($conversation->getId());
        // A running message is also pending; findNextQueued only picks queued.
        if (null === $message) {
            $pending = $this->messages->findPendingByConversation($conversation->getId());
            $message = $pending[0] ?? null;
        }
        if (null === $message) {
            // Nothing to attach the outcome to — sweep the orphan run.
            $this->em->remove($task);
            $this->em->flush();

            return;
        }

        $message->setReplyTaskId($task->getId());

        if ($failed) {
            $message->markFailed('' !== $reason ? $reason : 'Reply failed (worker error).');
        } else {
            $message->markCompleted();
            $assistant = new Message($conversation, Message::ROLE_ASSISTANT, $this->assistantContent($step));
            $assistant->markCompleted();
            $this->materializeToolLogs($assistant, $step);
            $conversation->addMessage($assistant);
            $this->em->persist($assistant);
        }

        $this->em->persist($message);
        $conversation->touch();

        // Hard-delete the transient run — cascade removes step/events/toolcalls.
        $this->em->remove($task);
        $this->em->flush();

        $this->logger->info('Reply materialized; transient run deleted', [
            'conversation' => $conversation->getId()->toRfc4122(),
            'failed' => $failed,
        ]);

        // Drive the next queued message (FIFO, one run at a time).
        $this->ensureReplyRun($conversation);
    }

    /**
     * Sweep orphaned reply runs: terminal conversation tasks that survived a
     * controller crash before materialization/cleanup. Idempotent.
     */
    public function sweepOrphanReplyRuns(): void
    {
        $orphans = $this->em->createQueryBuilder()
            ->select('t')
            ->from(Task::class, 't')
            ->where('t.conversationId IS NOT NULL')
            ->andWhere('t.status IN (:terminal)')
            ->setParameter('terminal', [Task::STATUS_COMPLETED, Task::STATUS_FAILED])
            ->getQuery()
            ->getResult();

        foreach ($orphans as $task) {
            $this->onStepTerminal($task, $task->getFinalStep() ?? $task->getSteps()->first(), Task::STATUS_FAILED === $task->getStatus());
        }
    }

    /**
     * @return string[]
     */
    public function responseTags(Conversation $conversation, Message $message): array
    {
        if ([] !== $message->getTags()) {
            return $message->getTags();
        }

        // Fallback: the conversation's seed tags (copied from the source
        // step's tags at follow-up time; empty for hand-created conversations).
        foreach ($conversation->getMessages() as $m) {
            if ($m->isSeed()) {
                return $m->getTags();
            }
        }

        return [];
    }

    /**
     * Render the turn context the worker sees (docs/conversations-plan.md
     * §6.1). User/assistant content only — no thinking blocks, no tool traces.
     */
    public function renderContext(Conversation $conversation, Message $current): string
    {
        $tz = $this->timezone->resolve();
        $now = new DateTimeImmutable();
        $lines = [];
        $lines[] = sprintf('Current date/time: %s (%s) timezone: %s', $now->format('Y-m-d H:i:s'), 'utc', $tz);
        $lines[] = '';
        $lines[] = '## Conversation history';

        $history = [];
        $chars = 0;
        foreach ($conversation->getMessages() as $m) {
            if ($m->getId()->equals($current->getId())) {
                break;
            }
            if (Message::ROLE_ASSISTANT === $m->getRole() && Message::STATUS_FAILED === $m->getStatus()) {
                continue; // never feed failures back as context
            }
            $entry = sprintf('%s: %s', $m->getRole(), $m->getContent());
            if (strlen($entry) + $chars > $this->historyChars) {
                break;
            }
            $history[] = $entry;
            $chars += strlen($entry);
        }
        $history = array_slice($history, -$this->historyMessages);

        if ([] === $history) {
            $lines[] = '(none)';
        } else {
            foreach ($history as $entry) {
                $lines[] = $entry;
            }
        }

        $lines[] = '';
        $lines[] = '## Current message';
        $lines[] = sprintf('user: %s', $current->getContent());

        return implode("\n", $lines);
    }

    /**
     * Seed content from a step_* event (docs/conversations-plan.md §6.3).
     */
    private function seedContent(Event $event): string
    {
        $payload = $event->getPayload();

        if (Event::TYPE_STEP_COMPLETED === $event->getType()) {
            $result = $payload['result'] ?? null;
            if (is_array($result) && is_string($result['summary'] ?? null)) {
                return $result['summary'];
            }
            if (null !== $result) {
                return (string) (json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '');
            }

            return '(step completed)';
        }

        if (Event::TYPE_STEP_FAILED === $event->getType()) {
            return (string) ($payload['reason'] ?? '(step failed)');
        }

        return (string) (json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * The assistant message content: the step's final `summary`, or the
     * pretty-printed result envelope when no summary is present.
     */
    private function assistantContent(Step $step): string
    {
        $result = $step->getResult();
        if (is_array($result) && is_string($result['summary'] ?? null)) {
            return $result['summary'];
        }
        if (null !== $result) {
            return (string) (json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '');
        }

        return '';
    }

    /**
     * Copy the run's tool executions into message_tool_log rows (D8: one
     * reply = one run = one message, so no run_id needed on the log).
     */
    private function materializeToolLogs(Message $assistant, Step $step): void
    {
        $runId = $step->getRunId();
        if (null === $runId) {
            return;
        }

        $events = $this->em->getRepository(Event::class)
            ->createQueryBuilder('e')
            ->where('IDENTITY(e.step) = :step')
            ->andWhere('e.runId = :run')
            ->orderBy('e.timestamp', 'ASC')
            ->setParameter('step', $step->getId()->toBinary(), Types::STRING)
            ->setParameter('run', $runId)
            ->getQuery()
            ->getResult();

        $toolCallsBySignature = $this->indexToolCalls($events);

        foreach ($events as $event) {
            if (Event::TYPE_TOOL_REQUESTED === $event->getType()) {
                $payload = $event->getPayload();
                $name = (string) ($payload['tool'] ?? '');
                $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];
                $log = new MessageToolLog($assistant, $name, MessageToolLog::KIND_EXTERNAL);
                $log->setArguments($args);

                $call = $toolCallsBySignature[$this->signature($name, $args)] ?? null;
                if ($call instanceof ToolCall) {
                    $log->setResult($call->getResponse());
                    $log->setError($call->getError());
                    $log->setStatus((string) ($call->getStatus() ?: MessageToolLog::STATUS_COMPLETED));
                    if (MessageToolLog::STATUS_DENIED === $log->getStatus()) {
                        $log->setStatus(MessageToolLog::STATUS_DENIED);
                    }
                } else {
                    $log->setStatus(MessageToolLog::STATUS_COMPLETED);
                }
                $assistant->addToolLog($log);
            } elseif (Event::TYPE_TOOL_INTERNAL === $event->getType()) {
                $payload = $event->getPayload();
                $name = (string) ($payload['tool'] ?? '');
                $log = new MessageToolLog($assistant, $name, MessageToolLog::KIND_INTERNAL);
                $log->setArguments(is_array($payload['args'] ?? null) ? $payload['args'] : []);
                $log->setResult(is_array($payload['result'] ?? null) ? $payload['result'] : null);
                $log->setStatus(MessageToolLog::STATUS_COMPLETED);
                $assistant->addToolLog($log);
            }
        }
    }

    /**
     * Index ToolCalls by tool-name + args signature so tool_requested events
     * can be matched to their persisted result (ToolCall hangs off the
     * llm_call event; the tool_requested event is a separate audit row).
     *
     * @param Event[] $events
     *
     * @return array<string, ToolCall>
     */
    private function indexToolCalls(array $events): array
    {
        $map = [];
        foreach ($events as $event) {
            foreach ($event->getToolCalls() as $toolCall) {
                $map[$this->signature($toolCall->getToolName(), $toolCall->getRequest())] = $toolCall;
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function signature(string $name, array $args): string
    {
        return $name.'|'.(string) (json_encode($args, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * Create the transient reply task (name/schedule/priority/step per plan
     * §5.1). The description carries the rendered transcript; the step tags
     * carry the response tags (governing the toolbox via ToolResolver).
     */
    private function createReplyRun(Conversation $conversation, Message $message): Task
    {
        $task = new Task('Reply to '.$conversation->getName(), '');
        $task->setStatus(Task::STATUS_READY);
        $task->setPriority(100);
        $task->setConversationId($conversation->getId());
        $task->setTimezone($this->timezone->resolve());

        $step = new Step('Reply', $this->renderContext($conversation, $message));
        $step->setIsFinal(true);
        $step->setSortOrder(0);
        $step->setTags($this->responseTags($conversation, $message));
        $task->addStep($step);

        $this->em->persist($task);
        $this->em->flush();

        $this->logger->info('Transient reply run created', [
            'task' => $task->getId()->toRfc4122(),
            'conversation' => $conversation->getId()->toRfc4122(),
        ]);

        return $task;
    }
}
