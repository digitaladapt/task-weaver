<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Worker;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Worker>
 */
class WorkerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Worker::class);
    }

    public function findByApiKey(string $apiKey): ?Worker
    {
        return $this->findOneBy(['apiKey' => $apiKey]);
    }

    public function findByEnrollmentToken(string $token): ?Worker
    {
        // Enrollment tokens are one-time (Tier-0) and not stored; the
        // lookup is done against the configured token value in the service.
        // A worker record is matched by name as a fallback for dev.
        return $this->findOneBy(['name' => $token]);
    }
}
