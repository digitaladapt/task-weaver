<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Event;
use App\Entity\Step;
use App\Entity\Task;
use App\Entity\Worker;

use function assert;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

use function json_encode;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Worker-reported step-start provenance (docs/model-selection-plan.md §5, M5b).
 *
 * The worker PATCHes /api/worker/step/{task}/{step}/status with the model it
 * will actually run; the controller records it on the step_started event.
 *
 * Regression guard: markStepRunning() logged the event but never flushed, so
 * the event vanished when the request ended — provenance never reached the
 * DB in live runs (only callers/tests that flushed manually ever saw it).
 */
final class StepStartedProvenanceTest extends WebTestCase
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

    public function testStepStartedEventSurvivesRequestWithWorkerReportedModel(): void
    {
        [$taskId, $step] = $this->seedReadyStep();

        $this->client->request('PATCH', '/api/worker/step/'.$taskId.'/'.$step->getId()->toRfc4122().'/status', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer provenance-key',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['status' => 'running', 'model' => 'Qwen3.6-35B-A3B-HauhauCS']));
        self::assertResponseIsSuccessful();

        $event = $this->findStepStartedEvent($step);
        self::assertNotNull($event, 'step_started event must persist when the request ends');
        self::assertSame('Qwen3.6-35B-A3B-HauhauCS', $event->getPayload()['model'] ?? null);
    }

    public function testStepStartedEventPersistsWithoutModel(): void
    {
        [$taskId, $step] = $this->seedReadyStep();

        $this->client->request('PATCH', '/api/worker/step/'.$taskId.'/'.$step->getId()->toRfc4122().'/status', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer provenance-key',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['status' => 'running']));
        self::assertResponseIsSuccessful();

        $event = $this->findStepStartedEvent($step);
        self::assertNotNull($event);
        self::assertArrayNotHasKey('model', $event->getPayload());
        self::assertArrayHasKey('expires_at', $event->getPayload());
    }

    /**
     * @return array{0: string, 1: Step}
     */
    private function seedReadyStep(): array
    {
        $em = $this->entityManager();

        $worker = new Worker('provenance-worker', ['terminal']);
        $worker->setApiKey('provenance-key');
        $em->persist($worker);

        $task = new Task('Provenance', 'Check the step_started event.');
        $task->setStatus(Task::STATUS_READY);
        $em->persist($task);

        $step = new Step('Run', 'Do the thing.');
        $step->setIsFinal(true);
        $step->setSortOrder(0);
        $task->addStep($step);

        $em->flush();

        return [$task->getId()->toRfc4122(), $step];
    }

    private function findStepStartedEvent(Step $step): ?Event
    {
        $em = $this->entityManager();

        // Re-read from the database — anything still sitting in the request's
        // unit of work (which the client discards at request end) must not
        // count as persisted provenance.
        $em->clear();

        $events = $em->getRepository(Event::class)->createQueryBuilder('e')
            ->where('IDENTITY(e.step) = :s')
            ->andWhere('e.type = :t')
            ->setParameter('s', $step->getId()->toBinary(), Types::STRING)
            ->setParameter('t', Event::TYPE_STEP_STARTED)
            ->getQuery()
            ->getResult();

        return $events[0] ?? null;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
