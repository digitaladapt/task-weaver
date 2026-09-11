<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * The oldest pending (queued) user message for a conversation — the next
     * one to be answered (FIFO, one at a time).
     */
    public function findNextQueued(Uuid $conversationId): ?Message
    {
        // UUID columns are BLOB in SQLite; bind the raw binary value (with
        // STRING type so the TEXT-affinity comparison matches what Doctrine
        // stored) rather than the RFC 4122 textual form.
        return $this->createQueryBuilder('m')
            ->where('IDENTITY(m.conversation) = :conversation')
            ->andWhere('m.role = :role')
            ->andWhere('m.status = :queued')
            ->setParameter('conversation', $conversationId->toBinary(), Types::STRING)
            ->setParameter('role', Message::ROLE_USER)
            ->setParameter('queued', Message::STATUS_QUEUED)
            ->orderBy('m.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * All user messages awaiting a reply, oldest first.
     *
     * @return Message[]
     */
    public function findPendingByConversation(Uuid $conversationId): array
    {
        return $this->createQueryBuilder('m')
            ->where('IDENTITY(m.conversation) = :conversation')
            ->andWhere('m.role = :role')
            ->andWhere('m.status IN (:statuses)')
            ->setParameter('conversation', $conversationId->toBinary(), Types::STRING)
            ->setParameter('role', Message::ROLE_USER)
            ->setParameter('statuses', [Message::STATUS_QUEUED, Message::STATUS_RUNNING])
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
