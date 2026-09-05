<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\TaskDueMessage;
use Psr\Log\LoggerInterface;

/**
 * Logs/acknowledges a task becoming due; the SchedulerService already did
 * the state transition before dispatching. Kept as a separate handler so the
 * flow is observable and hookable (e.g. notifications) without touching the
 * scheduler.
 */
#[AsMessageHandler]
final class TaskDueHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(TaskDueMessage $message): void
    {
        $this->logger->info('Task marked due', ['task' => $message->taskId]);
    }
}
