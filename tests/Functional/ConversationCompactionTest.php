<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\Step;
use App\Entity\Task;
use App\Entity\Worker;
use App\Service\ConversationService;
use App\Service\TaskWorkflowService;

use function assert;
use function count;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

use function strlen;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Conversation history compaction.
 *
 * When the transcript grows past a threshold, the controller spins up a
 * transient one-step "compact this" run (same lifecycle as a reply run).
 * On terminal state the LLM's summary is folded into the conversation's
 * rolling summary (summary + high-water mark), the run is hard-deleted, and
 * subsequent reply contexts render the summary plus only newer messages.
 *
 * Thresholds are env-tunable; tests read them from the container so the
 * assertions always match config.
 */
final class ConversationCompactionTest extends WebTestCase
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

    private function worker(): Worker
    {
        $em = $this->entityManager();
        $worker = new Worker('worker-1', ['terminal']);
        $worker->setApiKey('worker-key');
        $em->persist($worker);
        $em->flush();

        return $worker;
    }

    /**
     * Drive a step to terminal as a worker would. Steps/conversations are
     * re-read fresh so this survives the ConversationService em->clear() that
     * fires when a transient reply run is hard-deleted on terminal.
     *
     * @param array<string,mixed> $result
     */
    private function runStep(Step $step, Worker $worker, array $result = [], ?string $failReason = null): void
    {
        $em = $this->entityManager();
        $workflow = $this->client->getContainer()->get(TaskWorkflowService::class);
        assert($workflow instanceof TaskWorkflowService);

        // Re-read fresh entities so this survives the em->clear() that fires
        // when a transient reply run is hard-deleted on terminal.
        $em->clear();
        $step = $em->getRepository(Step::class)->find($step->getId());
        $worker = $em->getRepository(Worker::class)->find($worker->getId());
        assert($step instanceof Step);
        assert($worker instanceof Worker);

        $workflow->markStepRunning($step, $worker);
        if (null !== $failReason) {
            $workflow->failStep($step, $worker, $failReason);
        } else {
            $workflow->completeStep($step, $worker, $result);
        }
    }

    private function thresholds(): array
    {
        $c = static::getContainer();

        return [
            'messages' => (int) $c->getParameter('env(TASKWEAVER_COMPACTION_MESSAGE_THRESHOLD)'),
            'chars' => (int) $c->getParameter('env(TASKWEAVER_COMPACTION_CHAR_THRESHOLD)'),
        ];
    }

    /**
     * Persist `pairs` completed user→assistant turns (oldest first).
     *
     * @return Message[] the user messages, oldest first
     */
    private function seedTurns(Conversation $conversation, int $pairs): array
    {
        $em = $this->entityManager();
        $userMessages = [];
        for ($i = 1; $i <= $pairs; ++$i) {
            $u = new Message($conversation, Message::ROLE_USER, "question {$i}");
            $u->markCompleted();
            $conversation->addMessage($u);
            $em->persist($u);
            $userMessages[] = $u;

            $a = new Message($conversation, Message::ROLE_ASSISTANT, "answer {$i}");
            $a->markCompleted();
            $conversation->addMessage($a);
            $em->persist($a);
        }
        $em->flush();

        return $userMessages;
    }

    private function compactionRunFor(Conversation $conversation): ?Task
    {
        return $this->entityManager()->getRepository(Task::class)
            ->findOneBy(['compactionConversationId' => $conversation->getId()]);
    }

    public function testCompactionTriggersAfterThresholdAndStoresSummary(): void
    {
        $em = $this->entityManager();
        $worker = $this->worker();
        $t = $this->thresholds(); // 15 / 6000

        $service = $this->client->getContainer()->get(ConversationService::class);
        assert($service instanceof ConversationService);

        $conversation = new Conversation('Long chat');
        $em->persist($conversation);
        $em->flush();

        // Settled history just under the message threshold.
        $this->seedTurns($conversation, (int) ($t['messages'] / 2)); // 7 pairs = 14 turns

        // No compaction yet.
        $service->maybeCompact($conversation);
        self::assertNull($this->compactionRunFor($conversation));

        // One more live question (the current turn). Its reply will push the
        // completed-turn count past the threshold once materialized.
        $live = $service->postMessage($conversation, 'final question', ['terminal']);

        // Reply run exists; the compaction is not due until it resolves.
        self::assertNotNull($em->getRepository(Task::class)->findOneBy(['conversationId' => $conversation->getId()]));
        self::assertNull($this->compactionRunFor($conversation));

        // Drive the reply to completion → onStepTerminal materializes the
        // answer and now trips the threshold ⇒ a compaction run appears.
        $replyTask = $em->getRepository(Task::class)->findOneBy(['conversationId' => $conversation->getId()]);
        $this->runStep($replyTask->getFinalStep(), $worker, ['summary' => 'final answer']);

        $em->clear();
        $conversation = $em->getRepository(Conversation::class)->find($conversation->getId());
        $compact = $this->compactionRunFor($conversation);
        self::assertNotNull($compact, 'a compaction run should be created once the threshold is crossed');
        self::assertTrue($compact->isCompactionTask());
        self::assertFalse($compact->isReplyTask());
        self::assertSame(Task::STATUS_READY, $compact->getStatus());
        self::assertSame([], $compact->getFinalStep()->getTags(), 'compaction steps are tagless (claimable by any worker)');
        self::assertStringContainsString('Summarize the conversation', $compact->getFinalStep()->getDescription());

        // --- Worker claims & completes the compaction run ---
        $this->runStep($compact->getFinalStep(), $worker, ['summary' => 'condensed transcript v1']);

        $em->clear();
        $conversation = $em->getRepository(Conversation::class)->find($conversation->getId());

        // Summary stored + high-water mark moved to the latest assistant reply.
        self::assertTrue($conversation->hasSummary());
        self::assertSame('condensed transcript v1', $conversation->getSummary());
        $latest = null;
        foreach ($conversation->getMessages() as $m) {
            if (Message::ROLE_ASSISTANT === $m->getRole()) {
                $latest = $m;
            }
        }
        self::assertNotNull($latest);
        self::assertTrue(
            $latest->getId()->equals($conversation->getSummaryThroughMessageId()),
            'the summary high-water mark must be the latest assistant reply'
        );

        // No residue: compaction run hard-deleted.
        self::assertNull($this->compactionRunFor($conversation));
    }

    public function testSecondCompactionIsQuietlyDropped(): void
    {
        $em = $this->entityManager();
        $t = $this->thresholds();

        $service = $this->client->getContainer()->get(ConversationService::class);
        assert($service instanceof ConversationService);

        $conversation = new Conversation('Busy chat');
        $em->persist($conversation);
        $em->flush();
        $this->seedTurns($conversation, $t['messages'] + 1); // completed, well past the threshold

        // First compaction is created.
        $first = $service->maybeCompact($conversation);
        self::assertNotNull($first);
        self::assertNotNull($this->compactionRunFor($conversation));

        // Second request for the same conversation ⇒ quietly dropped (null),
        // and still exactly one live compaction run.
        $second = $service->maybeCompact($conversation);
        self::assertNull($second, 'a second compaction while one is live must be dropped');
        $runs = $em->getRepository(Task::class)->findBy(['compactionConversationId' => $conversation->getId()]);
        self::assertCount(1, $runs);
    }

    public function testFailedCompactionLeavesSummaryUntouchedAndCleansUp(): void
    {
        $em = $this->entityManager();
        $worker = $this->worker();
        $t = $this->thresholds();

        $service = $this->client->getContainer()->get(ConversationService::class);
        assert($service instanceof ConversationService);

        $conversation = new Conversation('Fragile chat');
        $em->persist($conversation);
        $em->flush();
        $this->seedTurns($conversation, $t['messages'] + 1);

        $compact = $service->maybeCompact($conversation);
        self::assertNotNull($compact);

        // Fail the compaction run.
        $this->runStep($compact->getFinalStep(), $worker, [], 'llm exploded');

        $em->clear();
        $conversation = $em->getRepository(Conversation::class)->find($conversation->getId());

        // Summary unchanged (none stored), run cleaned up.
        self::assertFalse($conversation->hasSummary());
        self::assertNull($conversation->getSummaryThroughMessageId());
        self::assertNull($this->compactionRunFor($conversation));

        // A fresh compaction is allowed again (the dedup guard cleared).
        $retry = $service->maybeCompact($conversation);
        self::assertNotNull($retry, 'a later compaction may proceed after a failure cleaned up');
    }

    public function testContextRendersSummaryPlusNewerMessagesOnly(): void
    {
        $em = $this->entityManager();
        $t = $this->thresholds();

        $service = $this->client->getContainer()->get(ConversationService::class);
        assert($service instanceof ConversationService);

        $conversation = new Conversation('Context chat');
        $em->persist($conversation);
        $em->flush();

        // Old history that will sit BEHIND the high-water mark.
        $this->seedTurns($conversation, $t['messages']);

        // Manually set a summary whose high-water mark covers the old turns.
        $cutoff = null;
        foreach ($conversation->getMessages() as $m) {
            if (Message::ROLE_ASSISTANT === $m->getRole()) {
                $cutoff = $m;
            }
        }
        $conversation->setSummary('everything up to now condensed', $cutoff->getId());
        $em->flush();

        // A fresh live question (not yet compacted).
        $live = $service->postMessage($conversation, 'what is new?', ['terminal']);

        $context = $service->renderContext($conversation, $live);

        // Summary section present; older raw turns are NOT inlined…
        self::assertStringContainsString('## Conversation summary (older history)', $context);
        self::assertStringContainsString('everything up to now condensed', $context);
        self::assertStringContainsString('## Recent messages', $context);
        self::assertStringNotContainsString('question 1', $context);
        self::assertStringNotContainsString('answer 1', $context);

        // …only the current message + summary.
        self::assertStringContainsString('## Current message', $context);
        self::assertStringContainsString('user: what is new?', $context);
    }

    public function testCompactionPromptFoldsSummaryForwardAndRecordsCutoff(): void
    {
        $em = $this->entityManager();
        $t = $this->thresholds();

        $service = $this->client->getContainer()->get(ConversationService::class);
        assert($service instanceof ConversationService);

        $conversation = new Conversation('Fold-forward chat');
        $em->persist($conversation);
        $em->flush();
        $this->seedTurns($conversation, $t['messages'] + 1); // 16 pairs = 32 messages

        // Pretend an earlier compaction folded the first two pairs.
        $oldMark = null;
        foreach ($conversation->getMessages() as $m) {
            if (Message::ROLE_ASSISTANT === $m->getRole() && 'answer 2' === $m->getContent()) {
                $oldMark = $m;
            }
        }
        self::assertNotNull($oldMark);
        $conversation->setSummary('earlier condensed notes', $oldMark->getId());
        $em->flush();

        $compact = $service->maybeCompact($conversation);
        self::assertNotNull($compact);

        // The prompt folds the previous summary forward and includes ONLY
        // messages after its high-water mark.
        $prompt = (string) $compact->getFinalStep()->getDescription();
        self::assertStringContainsString('### Summary so far', $prompt);
        self::assertStringContainsString('earlier condensed notes', $prompt);
        self::assertStringContainsString('user: question 3', $prompt);
        self::assertStringNotContainsString("user: question 1\n", $prompt);
        self::assertStringNotContainsString('answer 2', $prompt);

        // The run records the last message it actually folded as its cutoff.
        $last = null;
        foreach ($conversation->getMessages() as $m) {
            $last = $m;
        }
        self::assertNotNull($compact->getCompactionCutoffMessageId());
        self::assertTrue($compact->getCompactionCutoffMessageId()->equals($last->getId()));
    }

    public function testStaleCompactionCannotMoveHighWaterMarkBackwards(): void
    {
        $em = $this->entityManager();
        $worker = $this->worker();

        $conversation = new Conversation('Stale compaction');
        $em->persist($conversation);
        $em->flush();
        $this->seedTurns($conversation, 3);

        // Current summary covers the whole transcript.
        $messages = $conversation->getMessages()->toArray();
        $first = $messages[0];
        $last = $messages[count($messages) - 1];
        $conversation->setSummary('current summary text', $last->getId());
        $em->flush();

        // A stale compaction run — its cutoff sits behind the current mark —
        // finishes with text that must NOT be stored.
        $task = new Task('Compact stale', '');
        $task->setStatus(Task::STATUS_READY);
        $task->setCompactionConversationId($conversation->getId());
        $task->setCompactionCutoffMessageId($first->getId());
        $task->setTimezone('UTC');
        $step = new Step('Compact', 'stale prompt');
        $step->setIsFinal(true);
        $step->setSortOrder(0);
        $task->addStep($step);
        $em->persist($task);
        $em->flush();

        $this->runStep($task->getFinalStep(), $worker, ['summary' => 'stale summary']);

        $em->clear();
        $conversation = $em->getRepository(Conversation::class)->find($conversation->getId());

        // Summary unchanged, mark still on the latest message, run cleaned up.
        self::assertSame('current summary text', $conversation->getSummary());
        self::assertNotNull($conversation->getSummaryThroughMessageId());
        self::assertTrue($conversation->getSummaryThroughMessageId()->equals($last->getId()));
        self::assertNull($this->compactionRunFor($conversation));
    }

    public function testGiantMessageStillLetsCompactionAdvance(): void
    {
        $em = $this->entityManager();
        $t = $this->thresholds();

        $service = $this->client->getContainer()->get(ConversationService::class);
        assert($service instanceof ConversationService);

        $conversation = new Conversation('Giant paste chat');
        $em->persist($conversation);
        $em->flush();

        // One pair whose user message alone exceeds the whole fold budget.
        $u = new Message($conversation, Message::ROLE_USER, str_repeat('blob ', 10000)); // ~50k chars
        $u->markCompleted();
        $conversation->addMessage($u);
        $em->persist($u);
        $a = new Message($conversation, Message::ROLE_ASSISTANT, 'handled the blob');
        $a->markCompleted();
        $conversation->addMessage($a);
        $em->persist($a);
        $em->flush();

        // The char threshold trips immediately; compaction must still make
        // progress by folding a truncated form of the giant message.
        $compact = $service->maybeCompact($conversation);
        self::assertNotNull($compact, 'a giant first message must not stall compaction');

        $prompt = (string) $compact->getFinalStep()->getDescription();
        self::assertStringContainsString('…[truncated]', $prompt);
        self::assertLessThan(20000, strlen($prompt), 'the prompt stays near the fold budget');

        // Cutoff advanced past the giant message (its truncation consumes
        // the budget, so the follow-up reply stays raw for a later pass).
        // Coverage is honest: the mark is the last message actually folded.
        self::assertNotNull($compact->getCompactionCutoffMessageId());
        self::assertTrue($compact->getCompactionCutoffMessageId()->equals($u->getId()));
    }

    public function testCompactionRunExcludedFromNormalTaskLists(): void
    {
        $em = $this->entityManager();
        $t = $this->thresholds();

        $service = $this->client->getContainer()->get(ConversationService::class);
        assert($service instanceof ConversationService);

        $conversation = new Conversation('Hidden chat');
        $em->persist($conversation);
        $em->flush();
        $this->seedTurns($conversation, $t['messages'] + 1);
        self::assertNotNull($service->maybeCompact($conversation));

        $tasks = $em->getRepository(Task::class);

        // Compaction runs never surface in admin lists/scheduling counts.
        foreach ($tasks->findAllActive() as $task) {
            self::assertNull($task->getCompactionConversationId());
        }
        foreach ($tasks->findDue() as $task) {
            self::assertNull($task->getCompactionConversationId());
        }
        self::assertSame(0, $tasks->countActive());
    }
}
