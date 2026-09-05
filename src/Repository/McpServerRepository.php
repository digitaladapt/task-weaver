<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\McpServer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<McpServer>
 */
class McpServerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, McpServer::class);
    }

    /**
     * @return McpServer[]
     */
    public function findEnabled(): array
    {
        return $this->findBy(['enabled' => true]);
    }
}
