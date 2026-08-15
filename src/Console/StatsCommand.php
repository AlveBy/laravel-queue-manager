<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Console;

use AlveBy\QueueManager\Manager;
use Illuminate\Console\Command;

class StatsCommand extends Command
{
    protected $signature = 'queue-manager:stats
                            {--connection= : Limit to one queue connection}';

    protected $description = 'Show job counts and pause state for every queue';

    public function handle(Manager $manager): int
    {
        $rows = $manager->stats($this->option('connection'));

        if ($rows === []) {
            $this->components->warn('No queues found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Connection', 'Queue', 'Pending', 'Delayed', 'Reserved', 'Paused'],
            array_map(static fn (array $row): array => [
                $row['connection'],
                $row['queue'],
                $row['pending'],
                $row['delayed'],
                $row['reserved'],
                $row['paused'] ? 'yes' : '',
            ], $rows),
        );

        return self::SUCCESS;
    }
}
