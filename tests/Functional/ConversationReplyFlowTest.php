<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Conversation;
use App\Entity\Event;
use App\Entity\McpServer;
use App\Entity\Message;
use App\Entity\MessageToolLog;
use App\Entity\Step;
use App\Entity\Task;
use App\Entity\ToolCall;
use App\Entity\ToolDef;
use App\Entity\Worker;
use App\Service\ClaimService;
use App\Service\ConversationService;

use function assert;
use function count;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Conversations end-to-end (docs/conversations-plan.md §5, §7, §10).
 *
 * The core trick: a reply runs as a transient one-step task through the
 * existing worker pipeline; on terminal state the reply is materialized
 * (assistant message + message_tool_log) and the task/step/events/tool_calls
 * are HARD-DELETED — no residue in the task tables.
 */
final class ConversationReplyFlowTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        $em = $this->entityManager();
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function seedWorkerAndTool(): Worker
    {
        $em = $this->entityManager();

        $server = new McpServer('weather-api', McpServer::TRANSPORT_OPENAPI, 'https://api.example.com');
        $server->setEnabled(true);
        $em->persist($server);
        $tool = new ToolDef('get_weather', ['weather'], ['type' => 'object']);
        $server->addToolDef($tool);
        $em->persist($tool);

        $worker = new Worker('worker-1', ['terminal']);
        $worker->setApiKey('worker-key');
        $em->persist($worker);
        $em->flush();

        return $worker;
    }

    public function testReplyLifecycleMaterializesAndCleansUp(): void
    {
        $em = $this->entityManager();
        $worker = $this->seedWorkerAndTool();

        // --- Create a conversation + user message (a reply run is created) ---
        $conversation = new Conversation('Weather chat');
        $em->persist($conversation);
        $em->flush();

        $service = $this->client->getContainer()->get(ConversationService::class);
        assert($service instanceof ConversationService);

        $message = $service->postMessage($conversation, 'What is the weather in London?', ['weather', 'terminal']);

        // A transient one-final-step reply task exists.
        $replyTask = $em->getRepository(Task::class)->findOneBy(['conversationId' => $conversation->getId()]);
        self::assertNotNull($replyTask);
        self::assertTrue($replyTask->isReplyTask());
        self::assertSame(Task::STATUS_READY, $replyTask->getStatus());
        self::assertSame(100, $replyTask->getPriority());

        $step = $replyTask->getSteps()->first();
        self::assertInstanceOf(Step::class, $step);
        self::assertTrue($step->isFinal());
        // Context + tags govern the toolbox.
        self::assertStringContainsString('## Current message', $step->getDescription());
        self::assertContains('weather', $step->getTags());
        self::assertContains('terminal', $step->getTags());

        // --- Worker claims it (conversation reply wins pass 1) ---
        $claimService = $this->client->getContainer()->get(ClaimService::class);
        assert($claimService instanceof ClaimService);
        $claim = $claimService->claimFor($worker);
        self::assertNotNull($claim);
        self::assertSame($replyTask->getId(), $claim['task']->getId());

        // Claimed → message is running.
        $em->clear();
        $freshMessage = $em->getRepository(Message::class)->find($message->getId());
        self::assertSame(Message::STATUS_RUNNING, $freshMessage->getStatus());

        // --- Run: step_started + llm_call (mints/attaches run_id) ---
        $em->clear();
        $step = $em->getRepository(Step::class)->find($step->getId());
        // Re-fetch the worker after clear so events can reference a managed
        // entity.
        $worker = $em->getRepository(Worker::class)->find($worker->getId());
        $workflow = $this->client->getContainer()->get(\App\Service\TaskWorkflowService::class);
        $workflow->markStepRunning($step, $worker);

        // step_started (+ any registered llm_call) events carry the run_id.
        $em->flush();
        $events = $em->getRepository(Event::class)->createQueryBuilder('e')
            ->where('IDENTITY(e.step) = :s')
            ->setParameter('s', $step->getId()->toBinary(), \Doctrine\DBAL\Types\Types::STRING)
            ->getQuery()
            ->getResult();
        self::assertGreaterThanOrEqual(1, count($events));
        foreach ($events as $event) {
            self::assertSame($step->getRunId(), $event->getRunId());
        }

        // --- Complete (final step; reply materialized) ---
        $workflow->completeStep($step, $worker, ['summary' => 'It is sunny in London (18°C).']);

        $em->clear();

        // Durable record: assistant message with the answer.
        $assistant = $em->getRepository(Message::class)->findOneBy([
            'conversation' => $conversation->getId(),
            'role' => Message::ROLE_ASSISTANT,
        ]);
        self::assertNotNull($assistant);
        self::assertSame('It is sunny in London (18°C).', $assistant->getContent());
        self::assertSame(Message::STATUS_COMPLETED, $assistant->getStatus());

        // User message completed.
        $userMsg = $em->getRepository(Message::class)->find($message->getId());
        self::assertSame(Message::STATUS_COMPLETED, $userMsg->getStatus());
        self::assertNotNull($userMsg->getCompletedAt());

        // NO residue in task/step/event/tool_call for the reply run.
        self::assertNull($em->getRepository(Task::class)->find($replyTask->getId()));
        self::assertNull($em->getRepository(Step::class)->find($step->getId()));
        $replyEvents = $em->getRepository(Event::class)->createQueryBuilder('e')
            ->where('e.step = :step')->setParameter('step', $step->getId())
            ->getQuery()->getResult();
        self::assertCount(0, $replyEvents);
        self::assertCount(0, $em->getRepository(ToolCall::class)->findAll());
    }

    public function testFollowUpSeedsConversationFromStepEvent(): void
    {
        $em = $this->entityManager();
        $worker = $this->seedWorkerAndTool();

        // A completed task with a step_completed event.
        $task = new Task('Fetch weather', 'Get the weather for London.');
        $task->setStatus(Task::STATUS_COMPLETED);
        $em->persist($task);
        $step = new Step('Weather', 'Call get_weather for London.');
        $step->setTags(['weather']);
        $step->setIsFinal(true);
        $step->setSortOrder(0);
        $step->setStatus(Step::STATUS_COMPLETED);
        $step->setRunId('run-abc-123');
        $task->addStep($step);
        $em->persist($step);
        $em->flush();

        $event = new Event($step, $task, $worker, Event::TYPE_STEP_COMPLETED);
        $event->setPayload(['result' => ['summary' => 'Sunny in London.']]);
        $event->setRunId('run-abc-123');
        $em->persist($event);
        $em->flush();

        $service = $this->client->getContainer()->get(ConversationService::class);
        assert($service instanceof ConversationService);

        $conversation = $service->followUp($event);

        self::assertSame('run-abc-123', $conversation->getSourceRunId());
        self::assertSame($event->getId(), $conversation->getSourceEventId());
        self::assertStringContainsString('Follow up to Fetch weather', $conversation->getName());

        $seed = null;
        foreach ($conversation->getMessages() as $m) {
            if ($m->isSeed()) {
                $seed = $m;
            }
        }
        self::assertNotNull($seed);
        self::assertSame('Sunny in London.', $seed->getContent());
        self::assertSame(Message::ROLE_USER, $seed->getRole());
        self::assertSame(['weather'], $seed->getTags());
        self::assertSame('completed', $seed->getStatus());
    }

    public function testToolLogMaterializedWithInternalAndExternalCalls(): void
    {
        $em = $this->entityManager();
        $worker = $this->seedWorkerAndTool();

        $conversation = new Conversation('Weather chat');
        $em->persist($conversation);
        $em->flush();

        $service = $this->client->getContainer()->get(ConversationService::class);
        assert($service instanceof ConversationService);
        $service->postMessage($conversation, 'Get weather and remember it', ['weather', 'terminal']);

        $replyTask = $em->getRepository(Task::class)->findOneBy(['conversationId' => $conversation->getId()]);
        self::assertNotNull($replyTask);
        $step = $replyTask->getSteps()->first();

        // Worker claims + starts (mints run_id).
        $claimService = $this->client->getContainer()->get(ClaimService::class);
        assert($claimService instanceof ClaimService);
        $claim = $claimService->claimFor($worker);
        self::assertNotNull($claim);
        $em->clear();
        $worker = $em->getRepository(Worker::class)->find($worker->getId());
        $replyTask = $em->getRepository(Task::class)->find($replyTask->getId());
        $step = $em->getRepository(Step::class)->find($step->getId());
        $workflow = $this->client->getContainer()->get(\App\Service\TaskWorkflowService::class);
        $workflow->markStepRunning($step, $worker);

        // The worker registers its first llm_call event (this is the anchor
        // the ToolCall hangs off); the proxy then logs tool_requested /
        // tool_finished audit events with the same run_id.
        $event = $workflow->registerEvent($step, $worker);
        self::assertNotNull($event);
        $toolCall = new ToolCall($event, 'get_weather', ['location' => 'London']);
        $toolCall->setStatus('completed');
        $toolCall->setResponse(['result' => ['temperature' => 18, 'condition' => 'sunny']]);
        $event->addToolCall($toolCall);
        $em->persist($toolCall);

        // Emulate the proxy's audit events (tool_requested + tool_internal).
        $requested = new Event($step, $replyTask, $worker, Event::TYPE_TOOL_REQUESTED);
        $requested->setPayload(['tool' => 'get_weather', 'args' => ['location' => 'London']]);
        $requested->setRunId($step->getRunId());
        $em->persist($requested);

        $internal = new Event($step, $replyTask, $worker, Event::TYPE_TOOL_INTERNAL);
        $internal->setPayload(['tool' => 'memory', 'args' => ['note' => 'weather'], 'result' => ['ok' => true]]);
        $internal->setRunId($step->getRunId());
        $em->persist($internal);
        $em->flush();

        $workflow->completeStep($step, $worker, ['summary' => 'Weather fetched: sunny 18C.']);
        $em->clear();

        $assistant = $em->getRepository(Message::class)->findOneBy([
            'conversation' => $conversation->getId(),
            'role' => Message::ROLE_ASSISTANT,
        ]);
        self::assertNotNull($assistant);

        $logs = $em->getRepository(MessageToolLog::class)->findBy(['message' => $assistant->getId()]);
        self::assertCount(2, $logs);

        $external = null;
        $internalLog = null;
        foreach ($logs as $log) {
            if (MessageToolLog::KIND_EXTERNAL === $log->getKind()) {
                $external = $log;
            } elseif (MessageToolLog::KIND_INTERNAL === $log->getKind()) {
                $internalLog = $log;
            }
        }
        self::assertNotNull($external);
        self::assertSame('get_weather', $external->getToolName());
        self::assertSame(['location' => 'London'], $external->getArguments());
        self::assertSame(['result' => ['temperature' => 18, 'condition' => 'sunny']], $external->getResult());
        self::assertNotNull($internalLog);
        self::assertSame('memory', $internalLog->getToolName());
        self::assertSame(['ok' => true], $internalLog->getResult());

        // And the run + events are all gone.
        self::assertNull($em->getRepository(Task::class)->find($replyTask->getId()));
        self::assertCount(0, $em->getRepository(Event::class)->findAll());
        self::assertCount(0, $em->getRepository(ToolCall::class)->findAll());
    }

    public function testFailurePathMarksMessageFailedAndCleansUp(): void
    {
        $em = $this->entityManager();
        $worker = $this->seedWorkerAndTool();

        $conversation = new Conversation('Weather chat');
        $em->persist($conversation);
        $em->flush();

        $service = $this->client->getContainer()->get(ConversationService::class);
        assert($service instanceof ConversationService);
        $message = $service->postMessage($conversation, 'Anything?', ['terminal']);

        $replyTask = $em->getRepository(Task::class)->findOneBy(['conversationId' => $conversation->getId()]);
        self::assertNotNull($replyTask);
        $step = $replyTask->getSteps()->first();

        $em->clear();
        $worker = $em->getRepository(Worker::class)->find($worker->getId());
        $step = $em->getRepository(Step::class)->find($step->getId());
        $workflow = $this->client->getContainer()->get(\App\Service\TaskWorkflowService::class);
        $workflow->failStep($step, $worker, 'LLM unreachable');
        $em->clear();

        $fresh = $em->getRepository(Message::class)->find($message->getId());
        self::assertSame(Message::STATUS_FAILED, $fresh->getStatus());
        self::assertStringContainsString('LLM unreachable', (string) $fresh->getError());
        // Cleanup happened.
        self::assertNull($em->getRepository(Task::class)->find($replyTask->getId()));
    }

    public function testMultipleQueuedMessagesAnswerFifoOneAtATime(): void
    {
        $em = $this->entityManager();
        $worker = $this->seedWorkerAndTool();

        $conversation = new Conversation('FIFO chat');
        $em->persist($conversation);
        $em->flush();

        $service = $this->client->getContainer()->get(ConversationService::class);
        assert($service instanceof ConversationService);
        $first = $service->postMessage($conversation, 'First question', ['terminal']);
        $second = $service->postMessage($conversation, 'Second question', ['terminal']);

        // Only ONE live reply task at a time (unique conversation_id).
        $tasks = $em->getRepository(Task::class)->findBy(['conversationId' => $conversation->getId()]);
        self::assertCount(1, $tasks);
        $replyTask = $tasks[0];
        $step = $replyTask->getSteps()->first();

        // The context is the OLDEST queued message.
        self::assertStringContainsString('First question', $step->getDescription());
        self::assertStringNotContainsString('Second question', $step->getDescription());

        // Complete the first reply (mark running first — completeStep requires
        // the running state).
        $em->clear();
        $worker = $em->getRepository(Worker::class)->find($worker->getId());
        $step = $em->getRepository(Step::class)->find($step->getId());
        $workflow = $this->client->getContainer()->get(\App\Service\TaskWorkflowService::class);
        $workflow->markStepRunning($step, $worker);
        $workflow->completeStep($step, $worker, ['summary' => 'Answer one']);
        $em->clear();

        // First message completed, second still queued with a fresh run.
        $firstMsg = $em->getRepository(Message::class)->find($first->getId());
        self::assertSame(Message::STATUS_COMPLETED, $firstMsg->getStatus());
        $secondMsg = $em->getRepository(Message::class)->find($second->getId());
        self::assertSame(Message::STATUS_QUEUED, $secondMsg->getStatus());

        $newRun = $em->getRepository(Task::class)->findBy(['conversationId' => $conversation->getId()]);
        self::assertCount(1, $newRun);
        $newStep = $newRun[0]->getSteps()->first();
        self::assertStringContainsString('Second question', $newStep->getDescription());

        // Cleanup: the first run is gone.
        self::assertNull($em->getRepository(Task::class)->find($replyTask->getId()));
    }

    public function testFollowUpRejectsNonStepEvent(): void
    {
        $em = $this->entityManager();
        $worker = $this->seedWorkerAndTool();

        $task = new Task('Fetch weather', 'Get the weather.');
        $task->setStatus(Task::STATUS_READY);
        $em->persist($task);
        $step = new Step('Weather', 'Call get_weather.');
        $step->setTags(['weather']);
        $step->setIsFinal(true);
        $step->setSortOrder(0);
        $task->addStep($step);
        $em->persist($step);
        $em->flush();

        $event = new Event($step, $task, $worker, Event::TYPE_TOOL_REQUESTED);
        $event->setPayload(['tool' => 'get_weather', 'args' => ['location' => 'London']]);
        $em->persist($event);
        $em->flush();

        $service = $this->client->getContainer()->get(ConversationService::class);
        assert($service instanceof ConversationService);

        $this->expectException(LogicException::class);
        $service->followUp($event);
    }
}
