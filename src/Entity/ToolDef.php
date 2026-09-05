<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ToolDefRepository;

use function count;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ToolDefRepository::class)]
#[ORM\Table(name: 'tool_def')]
#[ORM\Index(columns: ['server_id'], name: 'idx_tooldef_server')]
class ToolDef
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: McpServer::class, inversedBy: 'toolDefs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private McpServer $server;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    /**
     * Tags determining which steps may call this tool.
     *
     * @var string[]
     */
    #[ORM\Column(type: Types::JSON)]
    private array $tags = [];

    /**
     * JSON Schema for the tool's arguments (as shipped to workers).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $schema = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    public function __construct(string $name, array $tags = [], array $schema = [])
    {
        $this->id = Uuid::v4();
        $this->name = $name;
        $this->tags = array_values(array_unique($tags));
        $this->schema = $schema;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getServer(): McpServer
    {
        return $this->server;
    }

    public function setServer(McpServer $server): void
    {
        $this->server = $server;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
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

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return $this->schema;
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function setSchema(array $schema): void
    {
        $this->schema = $schema;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    /**
     * Whether this tool's tags intersect the given step tags.
     */
    public function matchesTags(array $stepTags): bool
    {
        return count(array_intersect($this->tags, $stepTags)) > 0;
    }
}
