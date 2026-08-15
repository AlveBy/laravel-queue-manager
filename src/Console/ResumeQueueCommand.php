<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Console;

use AlveBy\QueueManager\Manager;
use Illuminate\Console\Command;

class ResumeQueueCommand extends Command
{
    protected $signature = 'queue-manager:resume
                            {queue? : The queue to resume}
                            {--all : Resume every paused queue}
                            {--connection= : Which queue connection}';

    protected $description = 'Let workers consume a paused queue again';

    public function handle(Manager $manager): int
    {
        if ($this->option('all')) {
            $count = $manager->resumeAll();

            $this->components->info("Resumed {$count} queue(s).");

            return self::SUCCESS;
        }

        $queue = $this->argument('queue');

        if ($queue === null) {
            $this->components->error('Name a queue, or pass --all.');

            return self::FAILURE;
        }

        $connection = $this->option('connection');

        if (! $manager->resume((string) $queue, $connection)) {
            $this->components->warn("Queue [{$queue}] was not paused.");

            return self::SUCCESS;
        }

        $this->components->info("Queue [{$queue}] resumed.");

        return self::SUCCESS;
    }
}
