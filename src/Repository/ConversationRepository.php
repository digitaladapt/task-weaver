<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conversation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    /**
     * All live conversations (neither archived nor soft-deleted), most
     * recently active first.
     *
     * @return Conversation[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.archivedAt IS NULL')
            ->andWhere('c.deletedAt IS NULL')
            ->orderBy('c.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Conversations that currently have a pending (queued or running) user
     * message and no live reply task yet — used by the claim safety net.
     *
     * @return Conversation[]
     */
    public function findWithPendingMessage(): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.messages', 'm')
            ->where('c.deletedAt IS NULL')
            ->andWhere('m.role = :role')
            ->andWhere('m.status IN (:statuses)')
            ->setParameter('role', 'user')
            ->setParameter('statuses', ['queued', 'running'])
            ->orderBy('c.updatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
