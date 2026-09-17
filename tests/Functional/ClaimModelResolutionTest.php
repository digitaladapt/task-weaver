<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Step;
use App\Entity\Task;
use App\Entity\Worker;

use function assert;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Claim-response model resolution (docs/model-selection-plan.md §5, M4).
 *
 * The claim response's step.model is the RESOLVED model for the run — the
 * step's per-step override if set, else the deployment default
 * (TASKWEAVER_LLM_MODEL). Always a string, never null: the worker honors it
 * unless a local operator override exists.
 */
final class ClaimModelResolutionTest extends WebTestCase
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

    public function testClaimResponseIncludesDefaultWhenStepModelNull(): void
    {
        $step = $this->readyTaskWithStep(null);

        $response = $this->claim();

        $default = self::getContainer()->getParameter('env(TASKWEAVER_LLM_MODEL)');
        self::assertIsString($default);

        self::assertSame($default, $response['step']['model']);
        self::assertNotNull($response['step']['model'] ?? null);
    }

    public function testClaimResponsePrefersStepModelOverride(): void
    {
        $this->readyTaskWithStep('Qwen3.6-35B-A3B-HauhauCS');

        $response = $this->claim();

        self::assertSame('Qwen3.6-35B-A3B-HauhauCS', $response['step']['model']);
    }

    /**
     * @return array<string, mixed>
     */
    private function claim(): array
    {
        $this->client->request('POST', '/api/worker/claim', server: ['HTTP_AUTHORIZATION' => 'Bearer worker-key']);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertResponseIsSuccessful();

        return $data;
    }

    private function readyTaskWithStep(?string $model): Step
    {
        $em = $this->entityManager();

        $worker = new Worker('worker-1', ['terminal']);
        $worker->setApiKey('worker-key');
        $em->persist($worker);

        $task = new Task('Model resolution', 'Verify claim model resolution.');
        $task->setStatus(Task::STATUS_READY);
        $em->persist($task);

        $step = new Step('Run', 'Do the thing.');
        $step->setIsFinal(true);
        $step->setSortOrder(0);
        $step->setModel($model);
        $task->addStep($step);
        $em->flush();

        return $step;
    }
}
