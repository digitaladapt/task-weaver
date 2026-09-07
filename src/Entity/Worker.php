<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WorkerRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function in_array;

use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WorkerRepository::class)]
#[ORM\Table(name: 'worker')]
class Worker
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    /**
     * Ephemeral worker-level API key, issued when the worker comes online.
     * Invalidated when worker settings change. (SPEC.md → Data Model).
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $apiKey = null;

    /**
     * Worker capability tags, assigned by the controller, not self-declared.
     * All workers carry `terminal`; variants add e.g. `php`, `node`.
     *
     * @var string[]
     */
    #[ORM\Column(type: Types::JSON)]
    private array $tags = [];

    /**
     * Tool names the worker can execute locally (memory, reasoning, etc.).
     * Noted at provisioning time; server-assigned, never self-declared.
     * The reference worker ships `terminal` (a safety stub until v1).
     *
     * @var string[]
     */
    #[ORM\Column(type: Types::JSON)]
    private array $internalTools = [];

    /**
     * Worker runtime config: timeouts, context limits, LLM endpoint, etc.,
     * issued by the controller when the worker comes online.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $config = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastSeenAt = null;

    /**
     * @var Collection<int, Event>
     */
    #[ORM\OneToMany(mappedBy: 'worker', targetEntity: Event::class)]
    private Collection $events;

    public function __construct(string $name, array $tags = [])
    {
        $this->id = Uuid::v4();
        $this->name = $name;
        $this->tags = array_values(array_unique($tags));
        $this->createdAt = new DateTimeImmutable();
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

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function setApiKey(?string $apiKey): void
    {
        $this->apiKey = $apiKey;
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
        $this->tags = array_values(array_unique($tags));
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    /**
     * @return string[]
     */
    public function getInternalTools(): array
    {
        return $this->internalTools;
    }

    /**
     * @param string[] $internalTools
     */
    public function setInternalTools(array $internalTools): void
    {
        $this->internalTools = array_values(array_unique($internalTools));
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSeenAt(): ?DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function markSeen(): void
    {
        $this->lastSeenAt = new DateTimeImmutable();
    }

    /**
     * @return Collection<int, Event>
     */
    public function getEvents(): Collection
    {
        return $this->events;
    }
}
