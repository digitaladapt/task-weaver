<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /**
     * Resolve an event by its event-scoped API key.
     */
    public function findByApiKey(string $apiKey): ?Event
    {
        return $this->findOneBy(['apiKey' => $apiKey]);
    }

    /**
     * Revoke every event key for a step (called on step `complete`).
     */
    public function revokeKeysForStep(string $stepId): int
    {
        return $this->createQueryBuilder('e')
            ->update()
            ->set('e.apiKey', ':null')
            ->where('e.step = :stepId')
            ->setParameter('null', null)
            ->setParameter('stepId', $stepId)
            ->getQuery()
            ->execute();
    }
}
