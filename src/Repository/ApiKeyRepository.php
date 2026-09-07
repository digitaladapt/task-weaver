<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiKey>
 */
class ApiKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiKey::class);
    }

    /**
     * Single-user app: at most one key exists.
     */
    public function hasAny(): bool
    {
        $count = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function findFirst(): ?ApiKey
    {
        return $this->createQueryBuilder('a')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
