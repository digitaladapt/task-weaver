<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MessageToolLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MessageToolLog>
 */
class MessageToolLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageToolLog::class);
    }
}
