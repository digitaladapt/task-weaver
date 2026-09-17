<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StepRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function in_array;

use InvalidArgumentException;

use function sprintf;

use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: StepRepository::class)]
#[ORM\Table(name: 'step')]
class Step
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RUNNING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Task::class, inversedBy: 'steps')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Task $task;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    /**
     * @var string[]
     */
    #[ORM\Column(type: Types::JSON)]
    private array $tags = [];

    /**
     * Optional per-step LLM model override (docs/model-selection-plan.md §3).
     * NULL = deployment default (TASKWEAVER_LLM_MODEL). Claim responses
     * resolve this to a concrete model string for the worker.
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isFinal = false;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::STRING, length: 16, options: ['default' => 'pending'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

    /**
     * Deadline set when the step is marked `running`: now + step-timeout.
     * Also gates the step's event keys. (SPEC.md → Stale Step Expiry).
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $expiresAt = null;

    /**
     * Result of the step; for the final step this is the aggregated envelope.
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $result = null;

    /**
     * The current execution's run id (docs/conversations-plan.md §4).
     * Minted when the step is marked `running`; cleared on reset for the
     * next run; retained on terminal step_* events for audit.
     */
    #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
    private ?string $runId = null;

    /**
     * @var Collection<int, Event>
     */
    #[ORM\OneToMany(mappedBy: 'step', targetEntity: Event::class, cascade: ['remove'], orphanRemoval: true)]
    private Collection $events;

    public function __construct(string $name, string $description)
    {
        $this->id = Uuid::v4();
        $this->name = $name;
        $this->description = $description;
        $this->events = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTask(): Task
    {
        return $this->task;
    }

    public function setTask(?Task $task): void
    {
        $this->task = $task;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
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

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): void
    {
        $this->model = null !== $model ? trim($model) : null;
        if ('' === $this->model) {
            $this->model = null;
        }
    }

    public function isFinal(): bool
    {
        return $this->isFinal;
    }

    public function setIsFinal(bool $isFinal): void
    {
        $this->isFinal = $isFinal;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(sprintf('Invalid step status "%s"', $status));
        }
        $this->status = $status;
    }

    public function getStartedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?DateTimeImmutable $startedAt): void
    {
        $this->startedAt = $startedAt;
    }

    public function getFinishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?DateTimeImmutable $finishedAt): void
    {
        $this->finishedAt = $finishedAt;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return self::STATUS_RUNNING === $this->status
            && null !== $this->expiresAt
            && $this->expiresAt < $now;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRunId(): ?string
    {
        return $this->runId;
    }

    public function setRunId(?string $runId): void
    {
        $this->runId = $runId;
    }

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

    /**
     * @return Collection<int, Event>
     */
    public function getEvents(): Collection
    {
        return $this->events;
    }
}
