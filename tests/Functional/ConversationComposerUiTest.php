<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\Worker;

use function assert;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

use function is_array;
use function sleep;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use function trim;

/**
 * Conversation composer UI (docs/conversations-plan.md §8).
 *
 * The send-message form mirrors the task step editor: same textarea styling,
 * same tag picker (bubbles + autocomplete dropdown), and the most recent
 * tagged message's tags prefilled. Sent messages are read-only — their tags
 * render in a collapsible section; there is no per-message update form.
 */
final class ConversationComposerUiTest extends WebTestCase
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
     * @param string[] $tags
     */
    private function addMessage(Conversation $conversation, string $role, string $content, array $tags = []): Message
    {
        $message = new Message($conversation, $role, $content);
        $message->setTags($tags);
        $message->markCompleted();
        $conversation->addMessage($message);

        $em = $this->entityManager();
        $em->persist($message);
        $em->flush();

        return $message;
    }

    public function testComposerPrefillsTagsFromTheMostRecentTaggedMessage(): void
    {
        $this->logIn();
        $em = $this->entityManager();

        $conversation = new Conversation('Prefill chat');
        $em->persist($conversation);
        $em->flush();

        // Distinct created_at values so "most recent" ordering is
        // deterministic (SQLite stores second precision).
        $this->addMessage($conversation, Message::ROLE_USER, 'First question', ['echo']);
        sleep(1);
        $this->addMessage($conversation, Message::ROLE_USER, 'Second question', ['weather', 'terminal']);
        // The newest message overall is the untagged assistant reply — it must
        // be skipped so the most recent *tagged* message's tags win.
        sleep(1);
        $this->addMessage($conversation, Message::ROLE_ASSISTANT, 'An answer');

        $crawler = $this->client->request('GET', '/conversations/'.$conversation->getId());
        self::assertResponseIsSuccessful();

        self::assertSame('weather,terminal', $crawler->filter('[data-tag-field] [data-tag-value]')->attr('value'));

        $bubbles = $crawler->filter('[data-tag-bubbles] .tag-bubble');
        self::assertCount(2, $bubbles);
        self::assertSame('weather', $bubbles->eq(0)->attr('data-tag'));
        self::assertSame('terminal', $bubbles->eq(1)->attr('data-tag'));
    }

    public function testComposerMatchesStepEditorMarkupAndDefaultsEmptyForFreshConversation(): void
    {
        $this->logIn();
        $em = $this->entityManager();

        // A worker supplies known tags so the picker's ALL_TAGS pool is populated.
        $worker = new Worker('composer-worker', ['terminal', 'weather']);
        $em->persist($worker);

        $conversation = new Conversation('Fresh chat');
        $em->persist($conversation);
        $em->flush();

        $crawler = $this->client->request('GET', '/conversations/'.$conversation->getId());
        self::assertResponseIsSuccessful();

        // Tag picker: the same structural pieces the task step editor uses.
        self::assertCount(1, $crawler->filter('[data-tag-field]'));
        self::assertCount(1, $crawler->filter('[data-tag-field] [data-tag-input]'));
        self::assertCount(1, $crawler->filter('[data-tag-field] [data-tag-dropdown]'));
        self::assertCount(1, $crawler->filter('[data-tag-field] input[type="hidden"][name="tags"]'));

        // No messages → nothing to prefill.
        self::assertSame('', $crawler->filter('[data-tag-value]')->attr('value'));
        self::assertCount(0, $crawler->filter('[data-tag-bubbles] .tag-bubble'));

        // The message textarea uses the task editor's .input styling.
        $textarea = $crawler->filter('textarea[name="content"]');
        self::assertCount(1, $textarea);
        self::assertSame('input', $textarea->attr('class'));

        // The known-tag pool for the autocomplete dropdown ships to the page.
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('const ALL_TAGS = ["terminal","weather"];', $html);
    }

    public function testSentMessageTagsAreReadOnlyAndUpdateEndpointIsGone(): void
    {
        $this->logIn();
        $em = $this->entityManager();

        $conversation = new Conversation('Read-only chat');
        $em->persist($conversation);
        $em->flush();

        $sent = $this->addMessage($conversation, Message::ROLE_USER, 'Will it rain?', ['weather']);

        $crawler = $this->client->request('GET', '/conversations/'.$conversation->getId());
        self::assertResponseIsSuccessful();

        // Tags still show — in a collapsible section — but read-only.
        $tags = $crawler->filter('.msg-bubble.user details.msg-tags');
        self::assertCount(1, $tags);
        self::assertSame('weather', trim($tags->filter('.tag')->first()->text()));
        self::assertCount(0, $tags->filter('form'));

        // No form anywhere posts to a per-message endpoint…
        self::assertCount(0, $crawler->filter('form[action*="/messages/"]'));
        // …and exactly one form posts to the composer endpoint.
        self::assertCount(1, $crawler->filter('form[action$="/messages"]'));

        // The old PATCH/POST per-message endpoint no longer resolves.
        $this->client->request('POST', '/conversations/'.$conversation->getId().'/messages/'.$sent->getId(), [
            '_csrf' => 'irrelevant',
            'tags' => 'echo',
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testFailedMessageShowsErrorDetailsExpandableSection(): void
    {
        $this->logIn();
        $em = $this->entityManager();

        $conversation = new Conversation('Error chat');
        $em->persist($conversation);
        $em->flush();

        $this->addMessage($conversation, Message::ROLE_USER, 'Hello there');
        sleep(1);
        $failed = new Message($conversation, Message::ROLE_ASSISTANT, '');
        $failed->markFailed('LLM upstream timeout after 30s (attempt 3/3)');
        $conversation->addMessage($failed);
        $em->persist($failed);
        $em->flush();

        $crawler = $this->client->request('GET', '/conversations/'.$conversation->getId());
        self::assertResponseIsSuccessful();

        // The failure reason renders in a collapsible section on the failed
        // message bubble — and only on that bubble.
        $error = $crawler->filter('.msg-bubble.assistant.failed details.msg-error');
        self::assertCount(1, $error);
        self::assertSame('error details', trim($error->filter('summary')->text()));
        self::assertStringContainsString('LLM upstream timeout after 30s (attempt 3/3)', $error->filter('pre')->text());

        // Successful messages get no error section at all.
        self::assertCount(0, $crawler->filter('.msg-bubble.user details.msg-error'));
        self::assertSame('Hello there', trim($crawler->filter('.msg-bubble.user .msg-content')->text()));

        // The failed message is still not rendered as a form (read-only display).
        self::assertCount(0, $crawler->filter('.msg-bubble.assistant.failed form'));
    }
}
