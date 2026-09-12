<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Event;
use App\Entity\McpServer;
use App\Entity\Message;
use App\Entity\Step;
use App\Entity\Task;
use App\Entity\ToolDef;
use App\Entity\Worker;

use function assert;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

use function is_array;
use function preg_match;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HTTP-level conversation flow (docs/conversations-plan.md §8).
 *
 * The admin UI posts a follow-up (CSRF), the thread renders, a message is
 * queued, and the worker claim/complete loop materializes the reply — with
 * the transient run hard-deleted. Proves the controller wiring end-to-end.
 */
final class ConversationHttpFlowTest extends WebTestCase
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

    /**
     * Admin-session bootstrap: create an API key via setup, then verify it
     * through the header-authenticated endpoint (establishes the cookie).
     */
    private function logIn(): void
    {
        $this->client->request('POST', '/api/auth/setup');
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        assert(is_array($data) && isset($data['api_key']));

        $this->client->request('POST', '/api/auth/verify', [], [], [
            'HTTP_X_API_KEY' => (string) $data['api_key'],
            'CONTENT_TYPE' => 'application/json',
        ]);
        self::assertResponseIsSuccessful();
    }

    private function seed(): array
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

        $task = new Task('Fetch weather', 'Get the weather for London.');
        $task->setStatus(Task::STATUS_COMPLETED);
        $em->persist($task);
        $step = new Step('Weather', 'Call get_weather for London.');
        $step->setTags(['weather']);
        $step->setIsFinal(true);
        $step->setSortOrder(0);
        $step->setStatus(Step::STATUS_COMPLETED);
        $step->setRunId('run-smoke-1');
        $task->addStep($step);
        $em->persist($step);
        $em->flush();

        $event = new Event($step, $task, $worker, Event::TYPE_STEP_COMPLETED);
        $event->setPayload(['result' => ['summary' => 'Sunny in London.']]);
        $event->setRunId('run-smoke-1');
        $em->persist($event);
        $em->flush();

        return [$worker, $event];
    }

    public function testFollowUpPostMessageClaimCompleteEndToEnd(): void
    {
        $this->logIn();
        [$worker, $event] = $this->seed();

        // --- Follow-up button POST (CSRF) → redirect to conversation ---
        $this->client->request('GET', '/tasks/'.$event->getTask()->getId()->toRfc4122());
        $html = (string) $this->client->getResponse()->getContent();
        if (!preg_match('/name="_csrf" value="([^"]+)"/', $html, $m)) {
            self::fail('No CSRF token in task show page');
        }
        $token = $m[1];
        $this->client->request('POST', '/conversations/follow-up', [
            '_csrf' => $token,
            'event_id' => $event->getId()->toRfc4122(),
        ]);
        self::assertResponseRedirects();

        // The conversation exists with the seed message.
        $em = $this->entityManager();
        $conversation = $em->getRepository(\App\Entity\Conversation::class)->findAll()[0];
        self::assertNotNull($conversation);
        self::assertStringContainsString('Sunny in London.', $conversation->getMessages()->first()->getContent());

        // --- Post a real user message (CSRF form POST) ---
        $this->client->request('GET', '/conversations/'.$conversation->getId());
        $html2 = (string) $this->client->getResponse()->getContent();
        if (!preg_match('/name="_csrf" value="([^"]+)"/', $html2, $m2)) {
            self::fail('No CSRF token in conversation page');
        }
        $this->client->request('POST', '/conversations/'.$conversation->getId().'/messages', [
            '_csrf' => $m2[1],
            'content' => 'What about tomorrow?',
            'tags' => 'weather',
        ]);
        self::assertResponseRedirects();

        // --- Worker claims (reply task wins pass 1) ---
        $this->client->request('POST', '/api/worker/claim', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer worker-key',
            'CONTENT_TYPE' => 'application/json',
        ], '{}');
        $claim = json_decode((string) $this->client->getResponse()->getContent(), true);
        assert(is_array($claim));
        self::assertIsArray($claim['task'] ?? null);
        $taskId = $claim['task']['id'];
        $stepId = $claim['step']['id'];

        // --- Worker marks running + completes ---
        $this->client->request('PATCH', '/api/worker/step/'.$taskId.'/'.$stepId.'/status', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer worker-key',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['status' => 'running']));
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/api/worker/step/'.$taskId.'/'.$stepId.'/complete', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer worker-key',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['result' => ['summary' => 'Likely sunny again.']]));
        self::assertResponseIsSuccessful();

        // --- Durable record: assistant message; run gone ---
        $em->clear();
        $messages = $em->getRepository(Message::class)->findAll();
        $assistant = null;
        foreach ($messages as $m) {
            if (Message::ROLE_ASSISTANT === $m->getRole()) {
                $assistant = $m;
            }
        }
        self::assertNotNull($assistant);
        self::assertSame('Likely sunny again.', $assistant->getContent());
        self::assertNull($em->getRepository(Task::class)->find($taskId));
        // The reply run's events are gone; only the seeded source event remains.
        $events = $em->getRepository(Event::class)->findAll();
        self::assertCount(1, $events);
        self::assertSame($event->getId()->toRfc4122(), $events[0]->getId()->toRfc4122());
    }
}
