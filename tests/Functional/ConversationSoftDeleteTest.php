<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Repository\ConversationRepository;

use function assert;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

use function is_array;
use function preg_match;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Conversation soft delete (parity with the task soft delete).
 *
 * The delete endpoint marks the conversation deleted_at (data preserved),
 * the index stops listing it, its page 404s, new messages are rejected,
 * and the reply-run safety net ignores it — while the row and its messages
 * stay in the database for history preservation.
 */
final class ConversationSoftDeleteTest extends WebTestCase
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

    /**
     * Create a conversation through the admin UI and give it one user
     * message, so persistence of messages can be asserted after deletion.
     */
    private function createConversation(string $name): Conversation
    {
        $this->client->request('GET', '/conversations/new');
        $html = (string) $this->client->getResponse()->getContent();
        if (!preg_match('/name="_csrf" value="([^"]+)"/', $html, $m)) {
            self::fail('No CSRF token in conversation new page');
        }

        $this->client->request('POST', '/conversations/new', [
            '_csrf' => $m[1],
            'name' => $name,
        ]);
        self::assertResponseRedirects();

        // Follow the redirect to the conversation page (also warms the
        // CSRF token for later POSTs) and grab the entity.
        $this->client->followRedirect();
        $id = (string) $this->client->getRequest()->attributes->get('id');
        $em = $this->entityManager();

        $conversation = $em->getRepository(Conversation::class)->find($id);
        assert($conversation instanceof Conversation);

        $message = new Message($conversation, Message::ROLE_USER, 'hello, this is a thread');
        $conversation->addMessage($message);
        $em->persist($message);
        $em->flush();

        return $conversation;
    }

    public function testDeleteHidesConversationButKeepsData(): void
    {
        $this->logIn();
        $conversation = $this->createConversation('Keep my history');
        $id = $conversation->getId()->toRfc4122();

        // The delete form is offered at the bottom of the conversation page.
        $this->client->request('GET', '/conversations/'.$id);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Delete Conversation', $html);
        self::assertStringContainsString('/conversations/'.$id.'/delete', $html);
        if (!preg_match('/name="_csrf" value="([^"]+)"/', $html, $m)) {
            self::fail('No CSRF token in conversation page');
        }

        // --- Delete (soft) via the new endpoint ---
        $this->client->request('POST', '/conversations/'.$id.'/delete', [
            '_csrf' => $m[1],
        ]);
        self::assertResponseRedirects('/conversations');

        // Flash confirmation on the index.
        $this->client->followRedirect();
        $index = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Conversation &quot;Keep my history&quot; deleted.', $index);
        self::assertStringNotContainsString('Keep my history</a>', $index);

        // Index action no longer lists it.
        /** @var ConversationRepository $repo */
        $repo = $this->entityManager()->getRepository(Conversation::class);
        self::assertSame([], $repo->findAllActive());

        // The row survives, marked deleted, with its messages intact.
        $em = $this->entityManager();
        $em->clear();
        $kept = $repo->find($id);
        self::assertNotNull($kept);
        self::assertTrue($kept->isDeleted());
        self::assertNotNull($kept->getDeletedAt());
        self::assertCount(1, $kept->getMessages());
        self::assertSame('hello, this is a thread', $kept->getMessages()->first()->getContent());
        self::assertCount(1, $em->getRepository(Message::class)->findAll());

        // Its page now 404s, like a task that was soft-deleted.
        $this->client->request('GET', '/conversations/'.$id);
        self::assertResponseStatusCodeSame(404);

        // Posting a new message to it is rejected too.
        $this->client->request('POST', '/conversations/'.$id.'/messages', [
            '_csrf' => $m[1],
            'content' => 'should not be accepted',
        ]);
        self::assertResponseStatusCodeSame(404);
        $em->clear();
        self::assertCount(1, $em->getRepository(Message::class)->findAll());

        // A second delete attempt 404s (already deleted).
        $this->client->request('POST', '/conversations/'.$id.'/delete', [
            '_csrf' => $m[1],
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteRequiresCsrfToken(): void
    {
        $this->logIn();
        $conversation = $this->createConversation('CSRF guard');
        $id = $conversation->getId()->toRfc4122();

        $this->client->request('POST', '/conversations/'.$id.'/delete');
        self::assertResponseStatusCodeSame(403);

        // And nothing was deleted.
        $this->entityManager()->clear();
        $kept = $this->entityManager()->getRepository(Conversation::class)->find($id);
        self::assertNotNull($kept);
        self::assertFalse($kept->isDeleted());
    }
}
