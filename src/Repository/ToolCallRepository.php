<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ToolCall;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ToolCall>
 */
class ToolCallRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ToolCall::class);
    }

    /**
     * Find a prior tool call by its idempotency key for an event — used for
     * deduping retried tool calls (SPEC.md Decisions Log #9).
     */
    public function findByIdempotencyKey(string $eventId, string $idempotencyKey): ?ToolCall
    {
        return $this->findOneBy([
            'event' => $eventId,
            'idempotencyKey' => $idempotencyKey,
        ]);
    }
}
