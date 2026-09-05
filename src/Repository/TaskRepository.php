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
     * Find tasks that are due to run: status `ready`, ordered by priority
     * (higher first) then creation time.
     *
     * @return Task[]
     */
    public function findDue(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.status = :ready')
            ->setParameter('ready', Task::STATUS_READY)
            ->orderBy('t.priority', 'DESC')
            ->addOrderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
