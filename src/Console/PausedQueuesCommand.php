<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Console;

use AlveBy\QueueManager\Manager;
use AlveBy\QueueManager\Support\PausedQueue;
use Illuminate\Console\Command;

class PausedQueuesCommand extends Command
{
    protected $signature = 'queue-manager:paused';

    protected $description = 'List every paused queue';

    public function handle(Manager $manager): int
    {
        $paused = $manager->pausedQueues();

        if ($paused === []) {
            $this->components->info('No queues are paused.');

            return self::SUCCESS;
        }

        $this->table(
            ['Connection', 'Queue', 'Paused at', 'Until', 'Reason'],
            array_map(static fn (PausedQueue $queue): array => [
                $queue->connection,
                $queue->queue,
                $queue->pausedAt > 0 ? $queue->pausedAtDate()->toDateTimeString() : 'unknown',
                $queue->untilDate()?->toDateTimeString() ?? '',
                $queue->reason ?? '',
            ], $paused),
        );

        return self::SUCCESS;
    }
}
