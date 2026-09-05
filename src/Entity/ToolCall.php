<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ToolCallRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ToolCallRepository::class)]
#[ORM\Table(name: 'tool_call')]
#[ORM\Index(columns: ['event_id'], name: 'idx_toolcall_event')]
#[ORM\Index(columns: ['idempotency_key'], name: 'idx_toolcall_idem')]
class ToolCall
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Event::class, inversedBy: 'toolCalls')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Event $event;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $toolName;

    /**
     * Arguments forwarded by the worker.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $request = [];

    /**
     * Result returned to the worker / propagated onward.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $response = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $status = null;

    /**
     * Client-supplied idempotency key, deduped server-side keyed by event.
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $idempotencyKey = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $timestamp;

    public function __construct(Event $event, string $toolName, array $request = [])
    {
        $this->id = Uuid::v4();
        $this->event = $event;
        $this->toolName = $toolName;
        $this->request = $request;
        $this->timestamp = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function setEvent(Event $event): void
    {
        $this->event = $event;
    }

    public function getToolName(): string
    {
        return $this->toolName;
    }

    public function setToolName(string $toolName): void
    {
        $this->toolName = $toolName;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRequest(): array
    {
        return $this->request;
    }

    /**
     * @param array<string, mixed> $request
     */
    public function setRequest(array $request): void
    {
        $this->request = $request;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResponse(): ?array
    {
        return $this->response;
    }

    /**
     * @param array<string, mixed>|null $response
     */
    public function setResponse(?array $response): void
    {
        $this->response = $response;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): void
    {
        $this->error = $error;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): void
    {
        $this->status = $status;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function setIdempotencyKey(?string $idempotencyKey): void
    {
        $this->idempotencyKey = $idempotencyKey;
    }

    public function getTimestamp(): DateTimeImmutable
    {
        return $this->timestamp;
    }
}
