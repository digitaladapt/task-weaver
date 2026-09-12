<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Conversation;
use App\Entity\McpServer;
use App\Entity\Message;
use App\Entity\Step;
use App\Entity\Task;
use App\Entity\ToolDef;
use App\Entity\Worker;
use App\Service\ClaimService;
use App\Service\ConversationService;
use App\Service\SchedulerService;
use App\Service\TaskWorkflowService;
use App\Service\TimezoneService;

use function assert;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Claim priority (docs/conversations-plan.md §5.2, D6): a pending conversation
 * reply is claimed BEFORE a normal ready step; when no conversation reply is
 * pending, normal tasks drain. Same sandbox-capability matching (external
 * tool tags are proxied, never a claim requirement) — regression belt.
 */
final class ClaimPriorityTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    private function claimService(): ClaimService
    {
        $em = $this->em;

        return new ClaimService(
            $em,
            $em->getRepository(Step::class),
            new TaskWorkflowService(
                $em,
                self::getContainer()->get(LoggerInterface::class),
                new SchedulerService($em, $em->getRepository(Task::class), new TimezoneService('UTC')),
                new ConversationService(
                    $em,
                    $em->getRepository(Message::class),
                    new TimezoneService('UTC'),
                    self::getContainer()->get(LoggerInterface::class),
                ),
            ),
            new ConversationService(
                $em,
                $em->getRepository(Message::class),
                new TimezoneService('UTC'),
                self::getContainer()->get(LoggerInterface::class),
            ),
        );
    }

    public function testConversationReplyWinsOverNormalReadyStep(): void
    {
        $em = $this->em;

        // Live external tool → weather is proxyable.
        $server = new McpServer('weather-api', McpServer::TRANSPORT_OPENAPI, 'https://api.example.com');
        $server->setEnabled(true);
        $em->persist($server);
        $tool = new ToolDef('get_weather', ['weather'], ['type' => 'object']);
        $server->addToolDef($tool);
        $em->persist($tool);

        $worker = new Worker('worker-1', ['terminal']);
        $worker->setApiKey('worker-key');
        $em->persist($worker);

        // Normal ready task + pending final step.
        $normal = new Task('Normal task', 'Run something.');
        $normal->setStatus(Task::STATUS_READY);
        $normal->setPriority(10);
        $em->persist($normal);
        $normalStep = new Step('Do', 'Do it.');
        $normalStep->setTags(['terminal']);
        $normalStep->setIsFinal(true);
        $normalStep->setSortOrder(0);
        $normal->addStep($normalStep);

        // Conversation with a queued message → transient reply task.
        $conversation = new Conversation('Chat');
        $em->persist($conversation);
        $msg = new Message($conversation, Message::ROLE_USER, 'Hello?');
        $msg->setTags(['terminal']);
        $em->persist($msg);
        $em->flush();

        // The reply run is created by the service (as if a message was posted).
        $conversationService = new ConversationService(
            $em,
            $em->getRepository(Message::class),
            new TimezoneService('UTC'),
            self::getContainer()->get(LoggerInterface::class),
        );
        $conversationService->ensureReplyRun($conversation);
        $em->flush();
        $em->clear();

        $claim = $this->claimService()->claimFor($worker);
        self::assertNotNull($claim);
        // The reply task wins (lower priority 100? no — normal task priority 10
        // vs reply priority 100; two-pass means conversations first regardless).
        self::assertTrue($claim['task']->isReplyTask());
        self::assertSame($conversation->getId()->toRfc4122(), $claim['task']->getConversationId()->toRfc4122());
    }

    public function testFallsThroughToNormalTaskWhenNoConversationPending(): void
    {
        $em = $this->em;

        $worker = new Worker('worker-1', ['terminal']);
        $worker->setApiKey('worker-key');
        $em->persist($worker);

        $normal = new Task('Normal task', 'Run something.');
        $normal->setStatus(Task::STATUS_READY);
        $normal->setPriority(10);
        $em->persist($normal);
        $normalStep = new Step('Do', 'Do it.');
        $normalStep->setTags(['terminal']);
        $normalStep->setIsFinal(true);
        $normalStep->setSortOrder(0);
        $normal->addStep($normalStep);
        $em->flush();
        $em->clear();

        $claim = $this->claimService()->claimFor($worker);
        self::assertNotNull($claim);
        self::assertFalse($claim['task']->isReplyTask());
    }

    public function testReplyStepStillRequiresSandboxCapabilities(): void
    {
        $em = $this->em;

        // No live external tool → 'terminal' is a real capability requirement.
        $worker = new Worker('empty', []);
        $worker->setApiKey('worker-key');
        $em->persist($worker);

        $conversation = new Conversation('Chat');
        $em->persist($conversation);
        $msg = new Message($conversation, Message::ROLE_USER, 'Run terminal');
        $msg->setTags(['terminal']);
        $em->persist($msg);
        $em->flush();

        $conversationService = new ConversationService(
            $em,
            $em->getRepository(Message::class),
            new TimezoneService('UTC'),
            self::getContainer()->get(LoggerInterface::class),
        );
        $conversationService->ensureReplyRun($conversation);
        $em->flush();
        $em->clear();

        $claim = $this->claimService()->claimFor($worker);
        self::assertNull($claim);
    }
}
