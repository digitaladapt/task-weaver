<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TaskRepository;

use function count;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function in_array;

use InvalidArgumentException;

use function sprintf;

use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TaskRepository::class)]
#[ORM\Table(name: 'task')]
class Task
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_READY = 'ready';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_READY,
        self::STATUS_RUNNING,
        self::STATUS_PAUSED,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $schedule = null;

    #[ORM\Column(type: Types::STRING, length: 64, options: ['default' => 'UTC'])]
    private string $timezone = 'UTC';

    #[ORM\Column(type: Types::STRING, length: 16, options: ['default' => 'draft'])]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $priority = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $nextRunAt = null;

    /**
     * Soft-delete marker. When set, the task is hidden from the admin UI and
     * excluded from scheduling/claiming/running, but it and its steps/events
     * are kept for history preservation (SPEC.md → Soft Delete).
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    /**
     * Non-null ⇒ this is a transient reply task for that conversation.
     * Unique ⇒ at most one live reply run per conversation at any time.
     * Hard-deleted after the reply is logged (D10).
     */
    #[ORM\Column(type: 'uuid', nullable: true, unique: true)]
    private ?Uuid $conversationId = null;

    /**
     * @var Collection<int, Step>
     */
    #[ORM\OneToMany(mappedBy: 'task', targetEntity: Step::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['isFinal' => 'ASC', 'sortOrder' => 'ASC'])]
    private Collection $steps;

    /**
     * @var Collection<int, Event>
     */
    #[ORM\OneToMany(mappedBy: 'task', targetEntity: Event::class, cascade: ['remove'], orphanRemoval: true)]
    private Collection $events;

    public function __construct(string $name, string $description)
    {
        $this->id = Uuid::v4();
        $this->name = $name;
        $this->description = $description;
        $this->createdAt = new DateTimeImmutable();
        $this->steps = new ArrayCollection();
        $this->events = new ArrayCollection();
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

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getSchedule(): ?string
    {
        return $this->schedule;
    }

    public function setSchedule(?string $schedule): void
    {
        $this->schedule = $schedule;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): void
    {
        $this->timezone = $timezone;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(sprintf('Invalid task status "%s"', $status));
        }
        $this->status = $status;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): void
    {
        $this->priority = $priority;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getNextRunAt(): ?DateTimeImmutable
    {
        return $this->nextRunAt;
    }

    public function setNextRunAt(?DateTimeImmutable $nextRunAt): void
    {
        $this->nextRunAt = $nextRunAt;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function softDelete(): void
    {
        $this->deletedAt = new DateTimeImmutable();
        // A soft-deleted task must not keep running. If it was scheduled,
        // clear the next run so the scheduler won't touch it again.
        $this->setNextRunAt(null);
        $this->touch();
    }

    public function restore(): void
    {
        $this->deletedAt = null;
        $this->touch();
    }

    /**
     * @return Collection<int, Step>
     */
    public function getSteps(): Collection
    {
        return $this->steps;
    }

    public function addStep(Step $step): void
    {
        if (!$this->steps->contains($step)) {
            $this->steps->add($step);
            $step->setTask($this);
        }
    }

    public function removeStep(Step $step): void
    {
        // Step.task is non-nullable, so we don't null the back-reference here;
        // orphanRemoval on the collection handles deleting the row on flush.
        // (Callers must not remove steps that have already started — see
        // TaskController::applyForm, which guards on startedAt.)
        $this->steps->removeElement($step);
    }

    /**
     * @return Collection<int, Event>
     */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    /**
     * Validates the two allowed flow shapes (SPEC.md → Flow Shapes):
     *   - Single step: one step (first = final).
     *   - Multi-step: 0..N non-final steps + exactly one final step.
     *
     * @throws InvalidArgumentException when the shape is invalid
     */
    public function assertValidShape(): void
    {
        $steps = $this->steps->toArray();
        if (0 === count($steps)) {
            throw new InvalidArgumentException('A task must have at least one step.');
        }

        $final = array_filter($steps, static fn (Step $s) => $s->isFinal());
        if (count($final) > 1) {
            throw new InvalidArgumentException('A task may have at most one final step.');
        }

        // For a single step, it must be the final step.
        if (1 === count($steps) && 0 === count($final)) {
            throw new InvalidArgumentException('A single-step task must mark its step as final.');
        }
    }

    public function getConversationId(): ?Uuid
    {
        return $this->conversationId;
    }

    public function setConversationId(?Uuid $conversationId): void
    {
        $this->conversationId = $conversationId;
    }

    public function isReplyTask(): bool
    {
        return null !== $this->conversationId;
    }

    public function getFinalStep(): ?Step
    {
        foreach ($this->steps as $step) {
            if ($step->isFinal()) {
                return $step;
            }
        }

        return null;
    }
}
