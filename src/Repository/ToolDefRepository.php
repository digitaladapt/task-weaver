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

    /**
     * Every tag currently carried by a live ToolDef (not removed, server
     * enabled). These are the "external tool" tags: TaskWeaver can proxy
     * any of them for ANY worker, so they are never a worker claim
     * requirement (SPEC.md → Claiming & Matching).
     *
     * @return string[] unique tag names
     */
    public function findLiveTagNames(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->join('t.server', 's')
            ->where('t.removedAt IS NULL')
            ->andWhere('s.enabled = :enabled')
            ->setParameter('enabled', true)
            ->select('t.tags')
            ->getQuery()
            ->getArrayResult();

        $tags = [];
        foreach ($rows as $row) {
            foreach ($row['tags'] ?? [] as $tag) {
                $tags[] = $tag;
            }
        }

        return array_values(array_unique($tags));
    }
}
