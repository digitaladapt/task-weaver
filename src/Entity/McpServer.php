<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\McpServerRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function in_array;

use InvalidArgumentException;

use function sprintf;

use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: McpServerRepository::class)]
#[ORM\Table(name: 'mcp_server')]
class McpServer
{
    public const TRANSPORT_OPENAPI = 'openapi';
    public const TRANSPORT_HTTP = 'http';

    public const TRANSPORTS = [self::TRANSPORT_OPENAPI, self::TRANSPORT_HTTP];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $transport = self::TRANSPORT_OPENAPI;

    #[ORM\Column(type: Types::STRING, length: 2048)]
    private string $endpoint;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Which env-var names map to this server's auth (headers/tokens).
     * Names only — values live in the environment, never the DB.
     *
     * @var string[]
     */
    #[ORM\Column(type: Types::JSON)]
    private array $credVars = [];

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, ToolDef>
     */
    #[ORM\OneToMany(mappedBy: 'server', targetEntity: ToolDef::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $toolDefs;

    public function __construct(string $name, string $transport, string $endpoint)
    {
        if (!in_array($transport, self::TRANSPORTS, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported MCP transport "%s"', $transport));
        }
        $this->id = Uuid::v4();
        $this->name = $name;
        $this->transport = $transport;
        $this->endpoint = $endpoint;
        $this->createdAt = new DateTimeImmutable();
        $this->toolDefs = new ArrayCollection();
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

    public function getTransport(): string
    {
        return $this->transport;
    }

    public function setTransport(string $transport): void
    {
        if (!in_array($transport, self::TRANSPORTS, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported MCP transport "%s"', $transport));
        }
        $this->transport = $transport;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function setEndpoint(string $endpoint): void
    {
        $this->endpoint = $endpoint;
    }

    /**
     * @return string[]
     */
    public function getCredVars(): array
    {
        return $this->credVars;
    }

    /**
     * @param string[] $credVars
     */
    public function setCredVars(array $credVars): void
    {
        $this->credVars = array_values(array_unique($credVars));
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, ToolDef>
     */
    public function getToolDefs(): Collection
    {
        return $this->toolDefs;
    }

    public function addToolDef(ToolDef $toolDef): void
    {
        if (!$this->toolDefs->contains($toolDef)) {
            $this->toolDefs->add($toolDef);
            $toolDef->setServer($this);
        }
    }
}
