<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Event;
use App\Entity\Step;
use App\Entity\Task;
use App\Entity\Worker;
use App\Service\TaskWorkflowService;

use function assert;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

use function json_encode;

use LogicException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Two-clock step liveness (docs/step-liveness-plan.md).
 *
 * - E2E clock:  `expires_at`        — absolute, never refreshed by activity.
 * - Idle clock: `idle_expires_at`   — rolling, refreshed by every activity,
 *                                     including streamed-output progress beats.
 *
 * A step is stale when EITHER deadline passes, and staleness is the single
 * authority: it gates event keys, progress beats, and late transitions.
 */
final class StepLivenessTest extends WebTestCase
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

    private function workflow(): TaskWorkflowService
    {
        $workflow = $this->client->getContainer()->get(TaskWorkflowService::class);
        assert($workflow instanceof TaskWorkflowService);

        return $workflow;
    }

    /**
     * @return array{0: Task, 1: Step, 2: Worker}
     */
    private function seedReadyStep(): array
    {
        $em = $this->entityManager();

        $worker = new Worker('liveness-worker', ['terminal']);
        $worker->setApiKey('liveness-key');
        $em->persist($worker);

        $task = new Task('Liveness', 'Check the two clocks.');
        $task->setStatus(Task::STATUS_READY);
        $em->persist($task);

        $step = new Step('Run', 'Do the thing.');
        $step->setIsFinal(true);
        $step->setSortOrder(0);
        $task->addStep($step);
        $em->flush();

        return [$task, $step, $worker];
    }

    // ── 1. Stamping ─────────────────────────────────────────────────────

    public function testMarkRunningStampsBothClocks(): void
    {
        [, $step, $worker] = $this->seedReadyStep();

        $before = new DateTimeImmutable();
        $this->workflow()->markStepRunning($step, $worker);

        self::assertNotNull($step->getExpiresAt(), 'e2e deadline must be stamped');
        self::assertNotNull($step->getIdleExpiresAt(), 'idle deadline must be stamped');

        // e2e is the long budget; idle is the short one. The pair is the
        // committed test config (600 / 120).
        $e2e = $step->getExpiresAt()->getTimestamp() - $before->getTimestamp();
        $idle = $step->getIdleExpiresAt()->getTimestamp() - $before->getTimestamp();
        self::assertEqualsWithDelta(600, $e2e, 3);
        self::assertEqualsWithDelta(120, $idle, 3);
        self::assertLessThan($e2e, $idle, 'idle must be shorter than e2e');
    }

    public function testStepStartedEventCarriesBothDeadlines(): void
    {
        [, $step, $worker] = $this->seedReadyStep();
        $this->workflow()->markStepRunning($step, $worker);

        $em = $this->entityManager();
        $em->clear();

        $event = $em->getRepository(Event::class)->findOneBy(['type' => Event::TYPE_STEP_STARTED]);
        self::assertNotNull($event);
        self::assertArrayHasKey('expires_at', $event->getPayload());
        self::assertArrayHasKey('idle_expires_at', $event->getPayload());
    }

    // ── 2. Activity rolls the idle clock, never the e2e clock ───────────

    public function testActivityRefreshesIdleButNeverE2e(): void
    {
        [, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();

        $workflow->markStepRunning($step, $worker);
        $e2eAtStart = $step->getExpiresAt();
        $idleAtStart = $step->getIdleExpiresAt();

        // Idle deadline is nearly up (but NOT passed — a stale step refuses
        // new activity by design, which has its own test), then register an
        // event — a real activity — and watch the clock roll forward.
        $step->setIdleExpiresAt(new DateTimeImmutable('+5 seconds'));
        $this->entityManager()->flush();
        $idleBefore = $step->getIdleExpiresAt();

        $workflow->registerEvent($step, $worker);

        // Idle rolled forward…

        self::assertGreaterThan($idleAtStart, $step->getIdleExpiresAt());
        // …but the absolute budget is untouched — activity can never extend
        // the end-to-end deadline.
        self::assertEquals($e2eAtStart->getTimestamp(), $step->getExpiresAt()?->getTimestamp());
    }

    public function testTouchActivityIgnoresNonRunningSteps(): void
    {
        [, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();

        $workflow->markStepRunning($step, $worker);
        $step->setStatus(Step::STATUS_COMPLETED);
        $idleBefore = $step->getIdleExpiresAt();

        $workflow->touchActivity($step);

        // A terminal step has no clocks to roll; a stray event must not
        // re-arm a deadline on a step that is already done.
        self::assertEquals($idleBefore?->getTimestamp(), $step->getIdleExpiresAt()?->getTimestamp());
    }

    // ── 3. Staleness predicate ──────────────────────────────────────────

    public function testStalenessIsEitherClock(): void
    {
        [, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);

        $now = new DateTimeImmutable();
        self::assertFalse($step->isStale($now), 'fresh step is not stale');

        // Idle-only expiry: e2e still has hours left.
        $step->setIdleExpiresAt($now->modify('-1 second'));
        self::assertFalse($step->hasExpiredE2e($now));
        self::assertTrue($step->hasExpiredIdle($now));
        self::assertTrue($step->isStale($now), 'idle deadline alone makes a step stale');

        // E2e-only expiry.
        $step->setIdleExpiresAt($now->modify('+1 hour'));
        $step->setExpiresAt($now->modify('-1 second'));
        self::assertTrue($step->hasExpiredE2e($now));
        self::assertFalse($step->hasExpiredIdle($now));
        self::assertTrue($step->isStale($now), 'e2e deadline alone makes a step stale');
    }

    public function testNullIdleDeadlineIsNeverStale(): void
    {
        [, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);

        // A NULL idle deadline (pre-migration row) must not be treated as a
        // stall — absence of a clock is not a stall.
        $step->setIdleExpiresAt(null);
        $now = new DateTimeImmutable();

        self::assertFalse($step->hasExpiredIdle($now));
        self::assertFalse($step->isStale($now));
    }

    // ── 4. Lazy expiry names the clock that tripped ─────────────────────

    public function testExpireStaleStepsReportsIdleReason(): void
    {
        [, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);

        // Only the idle clock has passed.
        $step->setIdleExpiresAt(new DateTimeImmutable('-5 minutes'));
        $this->entityManager()->flush();

        // Production realism: expiry runs in a LATER request than the one
        // that marked the step running, so the step (and its lazy events
        // collection, used to attribute the failure to a worker) is re-read
        // from the database rather than held in memory.
        $this->entityManager()->clear();

        $workflow->expireStaleSteps();

        $em = $this->entityManager();
        $em->clear();

        $fresh = $em->getRepository(Step::class)->find($step->getId());
        self::assertSame(Step::STATUS_FAILED, $fresh->getStatus(), 'idle-stale step must be failed');

        $failed = $em->getRepository(Event::class)->findOneBy(['type' => Event::TYPE_STEP_FAILED]);
        self::assertNotNull($failed);
        self::assertStringContainsString('no activity since', (string) ($failed->getPayload()['reason'] ?? ''));
    }

    public function testExpireStaleStepsReportsE2eReason(): void
    {
        [, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);

        // Only the absolute clock has passed; idle was just refreshed.
        $step->setExpiresAt(new DateTimeImmutable('-5 minutes'));
        $step->setIdleExpiresAt(new DateTimeImmutable('+1 hour'));
        $this->entityManager()->flush();

        // Same production realism as the idle-reason test: expiry re-reads
        // the step in a later request.
        $this->entityManager()->clear();

        $workflow->expireStaleSteps();

        $em = $this->entityManager();
        $em->clear();

        $fresh = $em->getRepository(Step::class)->find($step->getId());
        self::assertSame(Step::STATUS_FAILED, $fresh->getStatus());

        $failed = $em->getRepository(Event::class)->findOneBy(['type' => Event::TYPE_STEP_FAILED]);
        self::assertNotNull($failed);
        self::assertStringContainsString('step deadline reached', (string) ($failed->getPayload()['reason'] ?? ''));
    }

    public function testHealthyStepIsNeverReaped(): void
    {
        [, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);

        // A long-but-alive step: activity every half-idle-window. It must
        // survive repeated expiry sweeps — the regression that would make
        // this feature a nuisance instead of a safety net.
        for ($i = 0; $i < 4; ++$i) {
            $step->setIdleExpiresAt(new DateTimeImmutable('+60 seconds'));
            $this->entityManager()->flush();
            $workflow->expireStaleSteps();
        }

        $em = $this->entityManager();
        $em->clear();
        $fresh = $em->getRepository(Step::class)->find($step->getId());
        self::assertSame(Step::STATUS_RUNNING, $fresh->getStatus(), 'an active step must never be reaped');
    }

    public function testToolCallRefreshesTheIdleClock(): void
    {
        // Tool activity is step activity: a long tool call must never look
        // like a stalled worker. This exercises the ToolProxyService →
        // TaskWorkflowService wiring — if the dependency were left null, the
        // idle clock would silently stop refreshing and long tool calls
        // would be reaped as stalls.
        [$taskId, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);
        $event = $workflow->registerEvent($step, $worker);

        // Idle deadline is close; the tool call happens just before it lands.
        $step->setIdleExpiresAt(new DateTimeImmutable('+2 seconds'));
        $this->entityManager()->flush();
        $idleBefore = $step->getIdleExpiresAt();

        // Log an internal tool call for the step: this writes a
        // tool_internal event through ToolProxyService, which is the wiring
        // under test. If the injected TaskWorkflowService were null there,
        // the idle clock would silently stop refreshing on real activity and
        // long tool calls would be reaped as stalls.
        $this->client->request('POST', '/api/worker/tool/internal', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer liveness-key',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'event_id' => $event->getId()->toRfc4122(),
            'tool' => 'memory',
            'details' => ['note' => 'still working'],
        ]));

        self::assertResponseIsSuccessful();

        $em = $this->entityManager();
        $em->clear();

        $fresh = $em->getRepository(Step::class)->find($step->getId());
        self::assertGreaterThan(
            $idleBefore,
            $fresh->getIdleExpiresAt(),
            'tool activity must roll the idle clock forward'
        );
    }

    public function testZombieWorkerCannotRollItsOwnIdleClock(): void
    {
        // The idle deadline must be AUTHORITATIVE, not self-serve. Once a
        // step is stale, no activity route may roll its clock forward again —
        // otherwise a zombie worker could keep a dead step alive
        // indefinitely and dodge the very reaping the clock exists to trigger.
        [, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);

        $step->setIdleExpiresAt(new DateTimeImmutable('-1 second'));
        $this->entityManager()->flush();
        $staleDeadline = $step->getIdleExpiresAt();

        // The choke point every activity route goes through.
        $workflow->touchActivity($step);

        self::assertEquals(
            $staleDeadline,
            $step->getIdleExpiresAt(),
            'a stale step must not accept new activity'
        );

        // A progress beat is refused outright (→ 409 for the worker).
        $this->expectException(LogicException::class);
        $workflow->recordProgress($step);
    }

    // ── 5. Event keys are gated by both clocks ──────────────────────────

    public function testEventKeyIsInvalidWhenOnlyIdleClockPassed(): void
    {
        [, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);
        $event = $workflow->registerEvent($step, $worker);

        $now = new DateTimeImmutable();
        self::assertTrue($event->hasValidKey($now), 'fresh key is valid');

        // Idle-only expiry must invalidate the key just like the e2e one —
        // that is what makes the idle clock authoritative rather than
        // advisory.
        $step->setIdleExpiresAt($now->modify('-1 second'));
        self::assertFalse($event->hasValidKey($now), 'key must die with the idle clock');
    }

    public function testToolCallRejectedAfterIdleExpiry(): void
    {
        [$taskId, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);
        $event = $workflow->registerEvent($step, $worker);

        // Idle deadline passes (e2e still far away).
        $step->setIdleExpiresAt(new DateTimeImmutable('-1 second'));
        $this->entityManager()->flush();

        $this->client->request('POST', '/api/worker/tool/'.$taskId->getId()->toRfc4122().'/'.$event->getId()->toRfc4122(), [], [], [
            'HTTP_X_EVENT_KEY' => (string) $event->getApiKey(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['tool' => 'anything', 'args' => []]));

        // 401 → the worker abandons the step (abandon-on-denial).
        self::assertResponseStatusCodeSame(401);
    }

    // ── 6. Progress beats ───────────────────────────────────────────────

    public function testProgressBeatRefreshesIdleClockWithoutWritingAnEvent(): void
    {
        [$taskId, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);

        // Still alive, but the idle deadline is nearly up — exactly the state
        // a healthy-but-slow generation is in when a beat arrives.
        $step->setIdleExpiresAt(new DateTimeImmutable('+5 seconds'));
        $this->entityManager()->flush();
        $idleBefore = $step->getIdleExpiresAt();

        $em = $this->entityManager();
        $eventsBefore = $em->getRepository(Event::class)->count([]);

        $this->client->request('POST', '/api/worker/progress/'.$taskId->getId()->toRfc4122().'/'.$step->getId()->toRfc4122(), [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer liveness-key',
        ]);

        self::assertResponseIsSuccessful();

        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['ok'] ?? false);
        self::assertArrayHasKey('idle_expires_at', $body, 'beat returns the refreshed deadline');

        $em->clear();

        // The clock rolled forward…
        $fresh = $em->getRepository(Step::class)->find($step->getId());
        self::assertGreaterThan($idleBefore, $fresh->getIdleExpiresAt(), 'beat must roll the idle deadline forward');

        // …and nothing was written to the audit trail: a beat is a clock
        // refresh, not an observation.
        self::assertSame($eventsBefore, $em->getRepository(Event::class)->count([]), 'beats must not write event rows');
    }

    public function testProgressBeatCannotResurrectAStaleStep(): void
    {
        [$taskId, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);

        // Past BOTH deadlines.
        $step->setExpiresAt(new DateTimeImmutable('-1 second'));
        $step->setIdleExpiresAt(new DateTimeImmutable('-1 second'));
        $this->entityManager()->flush();

        $this->client->request('POST', '/api/worker/progress/'.$taskId->getId()->toRfc4122().'/'.$step->getId()->toRfc4122(), [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer liveness-key',
        ]);

        self::assertResponseStatusCodeSame(409);
    }

    public function testProgressBeatRequiresAValidWorkerKey(): void
    {
        [$taskId, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);

        $this->client->request('POST', '/api/worker/progress/'.$taskId->getId()->toRfc4122().'/'.$step->getId()->toRfc4122(), [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer wrong-key',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    // ── 7. Late transitions are rejected (§3.7) ─────────────────────────

    public function testCompleteRejectedAfterIdleExpiry(): void
    {
        [$taskId, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);

        $step->setIdleExpiresAt(new DateTimeImmutable('-1 second'));
        $this->entityManager()->flush();

        $this->client->request('POST', '/api/worker/step/'.$taskId->getId()->toRfc4122().'/'.$step->getId()->toRfc4122().'/complete', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer liveness-key',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['result' => ['summary' => 'too late']]));

        // A zombie worker may not write results after the deadline: the idle
        // clock must be authoritative, not advisory.
        self::assertResponseStatusCodeSame(409);
    }

    public function testCompleteAllowedWithinDeadlines(): void
    {
        [$taskId, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);

        $this->client->request('POST', '/api/worker/step/'.$taskId->getId()->toRfc4122().'/'.$step->getId()->toRfc4122().'/complete', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer liveness-key',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['result' => ['summary' => 'on time']]));

        self::assertResponseIsSuccessful();
    }

    // ── 8. Partial completion (§3.5) ────────────────────────────────────

    public function testPartialCompletionIsRecordedOnStepAndEvent(): void
    {
        [$taskId, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);

        $this->client->request('POST', '/api/worker/step/'.$taskId->getId()->toRfc4122().'/'.$step->getId()->toRfc4122().'/complete', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer liveness-key',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'result' => ['summary' => 'Half an answer'],
            'partial' => true,
            'reason' => 'idle timeout: LLM silent 105s',
        ]));

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['partial'] ?? false, 'the response must echo the truncation');

        $em = $this->entityManager();
        $em->clear();

        // A truncated step is COMPLETED (not failed): failure persists no
        // result, which would discard the partial output we saved.
        $fresh = $em->getRepository(Step::class)->find($step->getId());
        self::assertSame(Step::STATUS_COMPLETED, $fresh->getStatus());
        self::assertTrue($fresh->isPartial());
        self::assertSame('idle timeout: LLM silent 105s', $fresh->getPartialReason());
        self::assertSame(['summary' => 'Half an answer'], $fresh->getResult());

        // The audit trail records it too.
        $completed = $em->getRepository(Event::class)->findOneBy(['type' => Event::TYPE_STEP_COMPLETED]);
        self::assertNotNull($completed);
        self::assertTrue($completed->getPayload()['partial'] ?? false);
    }

    public function testOrdinaryCompletionIsNotMarkedPartial(): void
    {
        [$taskId, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);

        $this->client->request('POST', '/api/worker/step/'.$taskId->getId()->toRfc4122().'/'.$step->getId()->toRfc4122().'/complete', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer liveness-key',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['result' => ['summary' => 'Whole answer']]));

        self::assertResponseIsSuccessful();

        $em = $this->entityManager();
        $em->clear();

        $fresh = $em->getRepository(Step::class)->find($step->getId());
        self::assertFalse($fresh->isPartial(), 'a normal completion must not be flagged');
        self::assertNull($fresh->getPartialReason());

        $completed = $em->getRepository(Event::class)->findOneBy(['type' => Event::TYPE_STEP_COMPLETED]);
        self::assertNotNull($completed);
        self::assertArrayNotHasKey('partial', $completed->getPayload());
    }

    public function testFetchExposesPartialSoTheFinalStepKnows(): void
    {
        [$taskId, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);

        $this->client->request('POST', '/api/worker/step/'.$taskId->getId()->toRfc4122().'/'.$step->getId()->toRfc4122().'/complete', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer liveness-key',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'result' => ['summary' => 'truncated'],
            'partial' => true,
            'reason' => 'step deadline reached',
        ]));

        self::assertResponseIsSuccessful();

        // The worker fetches the task to build the final-step envelope; the
        // marker must ride along or the final step would treat a truncated
        // input as whole.
        $this->client->request('GET', '/api/worker/task/'.$taskId->getId()->toRfc4122(), [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer liveness-key',
        ]);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['steps'][0]['partial'] ?? false, 'fetch must expose the partial flag');
    }

    // ── 9. Budget block ─────────────────────────────────────────────────

    public function testStatusResponseCarriesBudgetBlock(): void
    {
        [$taskId, $step, $worker] = $this->seedReadyStep();

        $this->client->request('PATCH', '/api/worker/step/'.$taskId->getId()->toRfc4122().'/'.$step->getId()->toRfc4122().'/status', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer liveness-key',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['status' => 'running']));

        self::assertResponseIsSuccessful();

        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('budget', $body);
        self::assertArrayHasKey('e2e_remaining', $body['budget']);
        self::assertArrayHasKey('idle_timeout', $body['budget']);
        self::assertArrayHasKey('grace', $body['budget']);

        // Durations, not timestamps — the worker's clocks are skew-immune.
        self::assertIsInt($body['budget']['e2e_remaining']);
        self::assertIsInt($body['budget']['idle_timeout']);
        self::assertIsInt($body['budget']['grace']);
        self::assertLessThanOrEqual(600, $body['budget']['e2e_remaining']);
        self::assertSame(120, $body['budget']['idle_timeout']);
        self::assertSame(15, $body['budget']['grace']);
        self::assertArrayHasKey('idle_expires_at', $body);
    }

    public function testBudgetReflectsElapsedTime(): void
    {
        [$taskId, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);

        // 30s of the budget already spent when the worker asks.
        $step->setExpiresAt(new DateTimeImmutable('+570 seconds'));
        $this->entityManager()->flush();

        $budget = $workflow->budgetFor($step);

        // Derived from the stamped deadline, so it absorbs the delay between
        // stamping and the worker reading it.
        self::assertEqualsWithDelta(570, $budget['e2e_remaining'], 3);
    }

    // ── 9. Repository selection ─────────────────────────────────────────

    public function testIdleStaleStepIsClaimedAsStale(): void
    {
        [, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);

        // Idle-stale but e2e-fresh: the step-selection query must surface it
        // so lazy expiry can fix it (SPEC.md → Stale Step Expiry).
        $step->setIdleExpiresAt(new DateTimeImmutable('-1 second'));
        $step->getTask()->setStatus(Task::STATUS_READY);
        $this->entityManager()->flush();

        $em = $this->entityManager();
        $claimable = $em->getRepository(Step::class)->findClaimable(['terminal'], new DateTimeImmutable());
        self::assertNotNull($claimable, 'an idle-stale step must be re-selectable for lazy expiry');

        $stale = $em->getRepository(Step::class)->findStaleRunning(new DateTimeImmutable());
        self::assertCount(1, $stale);
    }

    public function testHealthyStepIsNotSelectedAsStale(): void
    {
        [, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);
        $this->entityManager()->flush();

        $em = $this->entityManager();
        $stale = $em->getRepository(Step::class)->findStaleRunning(new DateTimeImmutable());

        self::assertCount(0, $stale, 'a healthy running step must not look stale');
    }

    // ── 10. Reset paths clear both clocks ───────────────────────────────

    public function testResetForRunClearsBothClocks(): void
    {
        [$task, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);

        // Terminal, so the task may be re-run.
        $step->setStatus(Step::STATUS_COMPLETED);
        $workflow->resetForRun($task);

        self::assertNull($step->getExpiresAt());
        self::assertNull($step->getIdleExpiresAt(), 'a reset step must not carry a stale idle deadline');
        self::assertSame(Step::STATUS_PENDING, $step->getStatus());
    }

    public function testStaleReasonNamesTheTrippedClock(): void
    {
        [, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);
        $now = new DateTimeImmutable();

        $step->setExpiresAt($now->modify('+1 hour'));
        $step->setIdleExpiresAt($now->modify('-1 second'));
        self::assertStringContainsString('no activity since', $step->staleReason($now));

        $step->setExpiresAt($now->modify('-1 second'));
        $step->setIdleExpiresAt($now->modify('+1 hour'));
        self::assertStringContainsString('step deadline reached', $step->staleReason($now));
    }

    // ── 11. The e2e clock can still be used directly ────────────────────

    public function testLegacyIsExpiredStillMeansE2eOnly(): void
    {
        [, $step, $worker] = $this->seedReadyStep();
        $workflow = $this->workflow();
        $workflow->markStepRunning($step, $worker);
        $now = new DateTimeImmutable();

        // Idle-only: the legacy predicate must NOT report expiry (it means
        // the absolute budget, and callers rely on that meaning).
        $step->setIdleExpiresAt($now->modify('-1 second'));
        self::assertFalse($step->isExpired($now));
        self::assertTrue($step->isStale($now));
    }
}
