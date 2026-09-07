<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ApiKeyRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Admin API key for the web UI — the penny-track pattern.
 *
 * Single-user: exactly one row ever exists (setup refuses when a key is
 * already present). Only the bcrypt hash is stored; the plaintext key is
 * shown once at setup and then lives in the operator's browser
 * localStorage, verified against this hash on every session bootstrap.
 */
#[ORM\Entity(repositoryClass: ApiKeyRepository::class)]
#[ORM\Table(name: 'api_key')]
class ApiKey
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * Bcrypt hash of the 64-hex-char key. bcrypt truncates input at 72
     * bytes, which is fine here (64 chars < 72); PASSWORD_DEFAULT could
     * switch algorithms server-side, so the hash stays self-describing.
     */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $keyHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastUsedAt = null;

    public function __construct(string $keyHash)
    {
        $this->id = Uuid::v4();
        $this->keyHash = $keyHash;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getKeyHash(): string
    {
        return $this->keyHash;
    }

    public function markUsed(): void
    {
        $this->lastUsedAt = new DateTimeImmutable();
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
