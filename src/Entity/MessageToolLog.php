<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MessageToolLogRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Tool execution detail for one assistant message (docs/conversations-plan.md
 * §3.1 / §7).
 *
 * A copy of what the LLM did BEFORE producing the reply — one row per tool
 * execution (external + internal). Hangs off message_id (D8): the message
 * IS the run, so no run_id is needed. The source task/events are hard-deleted
 * after materialization; this table is the durable record.
 */
#[ORM\Entity(repositoryClass: MessageToolLogRepository::class)]
#[ORM\Table(name: 'message_tool_log')]
#[ORM\Index(columns: ['message_id'], name: 'idx_mtl_message')]
class MessageToolLog
{
    public const KIND_EXTERNAL = 'external';
    public const KIND_INTERNAL = 'internal';

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DENIED = 'denied';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Message::class, inversedBy: 'toolLogs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Message $message;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $toolName;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $kind;

    /**
     * Args the LLM requested.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $arguments = [];

    /**
     * Tool result (external) or recorded result (internal).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $result = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $status = self::STATUS_COMPLETED;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(Message $message, string $toolName, string $kind)
    {
        $this->id = Uuid::v4();
        $this->message = $message;
        $this->toolName = $toolName;
        $this->kind = $kind;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMessage(): Message
    {
        return $this->message;
    }

    public function setMessage(Message $message): void
    {
        $this->message = $message;
    }

    public function getToolName(): string
    {
        return $this->toolName;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * @return array<string, mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function setArguments(array $arguments): void
    {
        $this->arguments = $arguments;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResult(): ?array
    {
        return $this->result;
    }

    /**
     * @param array<string, mixed>|null $result
     */
    public function setResult(?array $result): void
    {
        $this->result = $result;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): void
    {
        $this->error = $error;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
