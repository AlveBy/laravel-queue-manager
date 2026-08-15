<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Console;

use AlveBy\QueueManager\Console\Concerns\ParsesDuration;
use AlveBy\QueueManager\Manager;
use Illuminate\Console\Command;

class PauseQueueCommand extends Command
{
    use ParsesDuration;

    protected $signature = 'queue-manager:pause
                            {queue : The queue to pause}
                            {--for= : Resume automatically after this long, e.g. 600 or "10 minutes"}
                            {--reason= : Recorded alongside the pause}
                            {--connection= : Which queue connection}';

    protected $description = 'Stop workers consuming a queue, without losing anything';

    public function handle(Manager $manager): int
    {
        $queue = (string) $this->argument('queue');

        $paused = $manager->pause(
            $queue,
            $this->parseDuration($this->option('for')),
            $this->option('reason'),
            $this->option('connection'),
        );

        $until = $paused->untilDate()?->toDateTimeString();

        $this->components->info(
            "Queue [{$paused->connection}:{$paused->queue}] paused".
            ($until !== null ? " until {$until}." : '.')
        );

        $counts = $manager->counts($paused->queue, $paused->connection);

        $this->components->twoColumnDetail('Waiting in queue', (string) $counts['pending']);
        $this->components->twoColumnDetail('Delayed', (string) $counts['delayed']);
        $this->components->twoColumnDetail('Reserved (may still be running)', (string) $counts['reserved']);

        return self::SUCCESS;
    }
}
