<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MessageRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function in_array;

use InvalidArgumentException;

use function sprintf;

use Symfony\Component\Uid\Uuid;

/**
 * One turn in a conversation (docs/conversations-plan.md).
 *
 * Content is the END RESULT only — an assistant message holds the final
 * answer text; thinking blocks and tool traces never appear here (they live
 * in message_tool_log). A user message may be queued / running / completed /
 * failed; an assistant message is always completed.
 */
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'message')]
#[ORM\Index(columns: ['conversation_id'], name: 'idx_message_conversation')]
class Message
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Conversation::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $role = self::ROLE_USER;

    #[ORM\Column(type: Types::TEXT)]
    private string $content = '';

    /**
     * Tool/capability tags governing the response to this message (§6.2).
     *
     * @var string[]
     */
    #[ORM\Column(type: Types::JSON)]
    private array $tags = [];

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    /**
     * Follow-up seed message (rendered specially in the UI).
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isSeed = false;

    /**
     * For seed messages: the event it was copied from.
     */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $sourceEventId = null;

    /**
     * Provenance: the throwaway reply task that produced the assistant reply.
     * The task itself is deleted after logging (D10) — this is a reference
     * to a gone row.
     */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $replyTaskId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $completedAt = null;

    /**
     * @var Collection<int, MessageToolLog>
     */
    #[ORM\OneToMany(mappedBy: 'message', targetEntity: MessageToolLog::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $toolLogs;

    public function __construct(Conversation $conversation, string $role, string $content)
    {
        $this->id = Uuid::v4();
        $this->conversation = $conversation;
        $this->role = $role;
        $this->content = $content;
        $this->createdAt = new DateTimeImmutable();
        $this->toolLogs = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function setConversation(Conversation $conversation): void
    {
        $this->conversation = $conversation;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): void
    {
        $this->role = $role;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    /**
     * @return string[]
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @param string[] $tags
     */
    public function setTags(array $tags): void
    {
        $this->tags = array_values(array_unique(array_map('trim', $tags)));
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(sprintf('Invalid message status "%s"', $status));
        }
        $this->status = $status;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): void
    {
        $this->error = $error;
    }

    public function isSeed(): bool
    {
        return $this->isSeed;
    }

    public function setIsSeed(bool $isSeed): void
    {
        $this->isSeed = $isSeed;
    }

    public function getSourceEventId(): ?Uuid
    {
        return $this->sourceEventId;
    }

    public function setSourceEventId(?Uuid $sourceEventId): void
    {
        $this->sourceEventId = $sourceEventId;
    }

    public function getReplyTaskId(): ?Uuid
    {
        return $this->replyTaskId;
    }

    public function setReplyTaskId(?Uuid $replyTaskId): void
    {
        $this->replyTaskId = $replyTaskId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function markCompleted(): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->completedAt = new DateTimeImmutable();
    }

    public function markRunning(): void
    {
        $this->status = self::STATUS_RUNNING;
    }

    public function markFailed(string $error): void
    {
        $this->status = self::STATUS_FAILED;
        $this->error = $error;
        $this->completedAt = new DateTimeImmutable();
    }

    /**
     * @return Collection<int, MessageToolLog>
     */
    public function getToolLogs(): Collection
    {
        return $this->toolLogs;
    }

    public function addToolLog(MessageToolLog $toolLog): void
    {
        if (!$this->toolLogs->contains($toolLog)) {
            $this->toolLogs->add($toolLog);
            $toolLog->setMessage($this);
        }
    }

    public function isPendingReply(): bool
    {
        return self::ROLE_USER === $this->role
            && in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }
}
