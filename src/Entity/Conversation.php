<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConversationRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A standalone chat thread (docs/conversations-plan.md).
 *
 * A conversation owns only its messages — there is no task/step/event
 * residue. Follow-ups set source_run_id / source_event_id purely as
 * provenance; reply runs are transient tasks hard-deleted after the reply
 * is logged (the durable record is message + message_tool_log).
 */
#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\Table(name: 'conversation')]
class Conversation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    /**
     * Provenance: the run the seed came from (D3). Optional; null for
     * hand-created conversations.
     */
    #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
    private ?string $sourceRunId = null;

    /**
     * The step_* event the follow-up was created from (optional provenance).
     */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $sourceEventId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * Touched on every message; used for sorting (updated_at).
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $archivedAt = null;

    /**
     * Soft-delete marker. When set, the conversation is hidden from the
     * admin UI and excluded from the reply-run safety net, but it and its
     * messages / tool logs are kept for history preservation — the same
     * policy as task.deleted_at (SPEC.md → Soft Delete).
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    /**
     * Rolling compacted transcript. One column max — each successful
     * compaction replaces the previous summary in full.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    /**
     * High-water mark for the summary: every message up to and including
     * this one is covered by `summary`; only newer messages render raw.
     */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $summaryThroughMessageId = null;

    /**
     * @var Collection<int, Message>
     */
    #[ORM\OneToMany(mappedBy: 'conversation', targetEntity: Message::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $messages;

    public function __construct(string $name)
    {
        $this->id = Uuid::v4();
        $this->name = $name;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
        $this->messages = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getSourceRunId(): ?string
    {
        return $this->sourceRunId;
    }

    public function setSourceRunId(?string $sourceRunId): void
    {
        $this->sourceRunId = $sourceRunId;
    }

    public function getSourceEventId(): ?Uuid
    {
        return $this->sourceEventId;
    }

    public function setSourceEventId(?Uuid $sourceEventId): void
    {
        $this->sourceEventId = $sourceEventId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getArchivedAt(): ?DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function archive(): void
    {
        $this->archivedAt = new DateTimeImmutable();
        $this->touch();
    }

    public function unarchive(): void
    {
        $this->archivedAt = null;
        $this->touch();
    }

    public function isArchived(): bool
    {
        return null !== $this->archivedAt;
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function softDelete(): void
    {
        $this->deletedAt = new DateTimeImmutable();
        $this->touch();
    }

    public function restore(): void
    {
        $this->deletedAt = null;
        $this->touch();
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    /**
     * Replace the rolling summary and move its high-water mark. Passing
     * null (compaction failed / superseded) clears the summary pair.
     */
    public function setSummary(?string $summary, ?Uuid $throughMessageId): void
    {
        $this->summary = $summary;
        $this->summaryThroughMessageId = $throughMessageId;
    }

    public function getSummaryThroughMessageId(): ?Uuid
    {
        return $this->summaryThroughMessageId;
    }

    public function hasSummary(): bool
    {
        return null !== $this->summary;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(Message $message): void
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setConversation($this);
        }
        $this->touch();
    }

    public function removeMessage(Message $message): void
    {
        $this->messages->removeElement($message);
        $this->touch();
    }
}
