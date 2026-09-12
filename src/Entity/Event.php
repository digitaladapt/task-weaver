<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EventRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'event')]
#[ORM\Index(columns: ['step_id'], name: 'idx_event_step')]
#[ORM\Index(columns: ['task_id'], name: 'idx_event_task')]
#[ORM\Index(columns: ['worker_id'], name: 'idx_event_worker')]
#[ORM\Index(columns: ['run_id'], name: 'idx_event_run')]
class Event
{
    // Event type discriminators (SPEC.md Decisions Log #2)
    public const TYPE_LLM_CALL = 'llm_call';
    public const TYPE_TOOL_REQUESTED = 'tool_requested';
    public const TYPE_TOOL_FINISHED = 'tool_finished';
    public const TYPE_TOOL_INTERNAL = 'tool_internal';
    public const TYPE_STEP_STARTED = 'step_started';
    public const TYPE_STEP_COMPLETED = 'step_completed';
    public const TYPE_STEP_FAILED = 'step_failed';
    public const TYPE_ERROR = 'error';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Step::class, inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Step $step;

    #[ORM\ManyToOne(targetEntity: Task::class, inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Task $task;

    #[ORM\ManyToOne(targetEntity: Worker::class, inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Worker $worker;

    /**
     * Event-scoped API key returned to the worker; authorizes tool calls for
     * this event only. Valid while the step is `running` and now < expires_at.
     * Revoked when the step's result is submitted (success or failure).
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $apiKey = null;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $type;

    /**
     * Free-form event data (structured by worker).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $timestamp;

    /**
     * Groups one step execution (one run): the initial llm_call mints it,
     * every subsequent tool_* and step_* event of that execution copies it
     * (docs/conversations-plan.md §4). No worker change — controller-side
     * audit grouping only.
     */
    #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
    private ?string $runId = null;

    /**
     * @var Collection<int, ToolCall>
     */
    #[ORM\OneToMany(mappedBy: 'event', targetEntity: ToolCall::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $toolCalls;

    public function __construct(Step $step, Task $task, Worker $worker, string $type)
    {
        $this->id = Uuid::v4();
        $this->step = $step;
        $this->task = $task;
        $this->worker = $worker;
        $this->type = $type;
        $this->timestamp = new DateTimeImmutable();
        $this->toolCalls = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getStep(): Step
    {
        return $this->step;
    }

    public function getTask(): Task
    {
        return $this->task;
    }

    public function getWorker(): Worker
    {
        return $this->worker;
    }

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function setApiKey(?string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function hasValidKey(DateTimeImmutable $now): bool
    {
        // Key is valid only while the step is running and within its deadline.
        return null !== $this->apiKey
            && Step::STATUS_RUNNING === $this->step->getStatus()
            && !$this->step->isExpired($now);
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setPayload(array $payload): void
    {
        $this->payload = $payload;
    }

    public function getRunId(): ?string
    {
        return $this->runId;
    }

    public function setRunId(?string $runId): void
    {
        $this->runId = $runId;
    }

    public function getTimestamp(): DateTimeImmutable
    {
        return $this->timestamp;
    }

    /**
     * Override the recorded timestamp (used by seeding/tests to backdate).
     */
    public function stamp(DateTimeImmutable $at): void
    {
        $this->timestamp = $at;
    }

    /**
     * @return Collection<int, ToolCall>
     */
    public function getToolCalls(): Collection
    {
        return $this->toolCalls;
    }

    public function addToolCall(ToolCall $toolCall): void
    {
        if (!$this->toolCalls->contains($toolCall)) {
            $this->toolCalls->add($toolCall);
            $toolCall->setEvent($this);
        }
    }
}
