<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\Event;
use App\Entity\Message;
use App\Repository\ConversationRepository;
use App\Repository\EventRepository;
use App\Repository\MessageRepository;
use App\Service\ConversationService;
use App\Service\TagService;

use function array_filter;
use function array_map;
use function array_values;

use Doctrine\ORM\EntityManagerInterface;

use function explode;

use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

use function trim;

/**
 * Conversation UI + message posting (docs/conversations-plan.md §8).
 */
#[Route('/conversations')]
final class ConversationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConversationService $conversations,
    ) {
    }

    #[Route('', name: 'app_conversations', methods: ['GET'])]
    public function index(ConversationRepository $repo): Response
    {
        $conversations = $repo->findAllActive();

        return $this->render('admin/conversations/index.html.twig', [
            'conversations' => $conversations,
        ]);
    }

    #[Route('/new', name: 'app_conversation_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $name = trim((string) $request->request->get('name', ''));
            if ('' === $name) {
                $this->addFlash('error', 'A conversation name is required.');

                return $this->redirectToRoute('app_conversation_new');
            }

            $conversation = new Conversation($name);
            $this->em->persist($conversation);
            $this->em->flush();
            $this->addFlash('success', 'Conversation created.');

            return $this->redirectToRoute('app_conversation_show', ['id' => $conversation->getId()->toRfc4122()]);
        }

        return $this->render('admin/conversations/new.html.twig');
    }

    #[Route('/{id}', name: 'app_conversation_show', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function show(
        ConversationRepository $repo,
        TagService $tags,
        string $id,
    ): Response {
        $conversation = $this->findConversation($repo, $id);

        return $this->render('admin/conversations/show.html.twig', [
            'conversation' => $conversation,
            'all_tags' => $tags->allKnown(),
        ]);
    }

    #[Route('/{id}/messages', name: 'app_conversation_message', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function message(
        ConversationRepository $repo,
        Request $request,
        string $id,
    ): Response {
        $conversation = $this->findConversation($repo, $id);

        $content = trim((string) $request->request->get('content', ''));
        if ('' === $content) {
            $this->addFlash('error', 'A message is required.');

            return $this->redirectToRoute('app_conversation_show', ['id' => $id]);
        }

        $tags = $this->parseTags((string) $request->request->get('tags', ''));
        $this->conversations->postMessage($conversation, $content, $tags);
        $this->addFlash('success', 'Message queued for a worker.');

        return $this->redirectToRoute('app_conversation_show', ['id' => $id]);
    }

    #[Route('/{id}/messages/{messageId}', name: 'app_conversation_message_tags', methods: ['PATCH', 'POST'], requirements: ['id' => '[0-9a-fA-F-]{36}', 'messageId' => '[0-9a-fA-F-]{36}'])]
    public function messageTags(
        ConversationRepository $repo,
        MessageRepository $messages,
        Request $request,
        string $id,
        string $messageId,
    ): Response {
        $conversation = $this->findConversation($repo, $id);

        $message = $messages->find(Uuid::fromString($messageId)->toRfc4122());
        if (!$message instanceof Message || $message->getConversation()->getId() !== $conversation->getId()) {
            throw $this->createNotFoundException('Message not found');
        }

        $tags = $this->parseTags((string) $request->request->get('tags', ''));
        $message->setTags($tags);
        $conversation->touch();
        $this->em->flush();
        $this->addFlash('success', 'Message tags updated.');

        return $this->redirectToRoute('app_conversation_show', ['id' => $id]);
    }

    #[Route('/follow-up', name: 'app_conversation_followup', methods: ['POST'])]
    public function followUp(Request $request, EventRepository $events): Response
    {
        $eventId = trim((string) $request->request->get('event_id', ''));
        $event = $events->find(Uuid::fromString($eventId)->toRfc4122());
        if (!$event instanceof Event) {
            throw $this->createNotFoundException('Event not found');
        }

        try {
            $conversation = $this->conversations->followUp($event);
        } catch (LogicException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_task_show', ['id' => $event->getTask()->getId()->toRfc4122()]);
        }

        return $this->redirectToRoute('app_conversation_show', ['id' => $conversation->getId()->toRfc4122()]);
    }

    private function findConversation(ConversationRepository $repo, string $id): Conversation
    {
        $conversation = $repo->find(Uuid::fromString($id)->toRfc4122());
        if (!$conversation instanceof Conversation || $conversation->isArchived()) {
            throw $this->createNotFoundException('Conversation not found');
        }

        return $conversation;
    }

    /**
     * @return string[]
     */
    private function parseTags(string $raw): array
    {
        if ('' === trim($raw)) {
            return [];
        }

        $tags = array_map(static fn (string $t) => trim($t), explode(',', $raw));

        return array_values(array_filter($tags, static fn (string $t) => '' !== $t));
    }
}
