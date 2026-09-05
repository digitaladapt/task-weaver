<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SchedulerTick;
use App\Service\SchedulerService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SchedulerTickHandler
{
    public function __construct(
        private readonly SchedulerService $scheduler,
    ) {
    }

    public function __invoke(SchedulerTick $message): void
    {
        $this->scheduler->tick($message->at);
    }
}
