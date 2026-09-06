<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ToolDef;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ToolDef>
 */
class ToolDefRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ToolDef::class);
    }

    public function findByName(string $name): ?ToolDef
    {
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * Find a tool on a given server by name (the natural key per server).
     */
    public function findByNameForServer(string $name, string $serverId): ?ToolDef
    {
        return $this->findOneBy(['name' => $name, 'server' => $serverId]);
    }
}
