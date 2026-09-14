<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Task;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Task>
 */
class TaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    /**
     * Find tasks that are due to run: status `ready`, not soft-deleted, ordered
     * by priority (higher first) then creation time.
     *
     * @return Task[]
     */
    public function findDue(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.status = :ready')
            ->andWhere('t.deletedAt IS NULL')
            ->andWhere('t.conversationId IS NULL')
            ->andWhere('t.compactionConversationId IS NULL')
            ->setParameter('ready', Task::STATUS_READY)
            ->orderBy('t.priority', 'DESC')
            ->addOrderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All tasks that are not soft-deleted, newest first.
     *
     * @return Task[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.deletedAt IS NULL')
            ->andWhere('t.conversationId IS NULL')
            ->andWhere('t.compactionConversationId IS NULL')
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Number of non-soft-deleted tasks.
     */
    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.deletedAt IS NULL')
            ->andWhere('t.conversationId IS NULL')
            ->andWhere('t.compactionConversationId IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Recent non-soft-deleted tasks.
     *
     * @return Task[]
     */
    public function findRecentActive(int $limit): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.deletedAt IS NULL')
            ->andWhere('t.conversationId IS NULL')
            ->andWhere('t.compactionConversationId IS NULL')
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
