<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Console;

use AlveBy\QueueManager\Manager;
use AlveBy\QueueManager\Support\JobState;
use Illuminate\Console\Command;

class PurgeQueueCommand extends Command
{
    protected $signature = 'queue-manager:purge
                            {queue : The queue to empty}
                            {--state=* : pending and/or delayed and/or reserved. Defaults to pending + delayed}
                            {--connection= : Which queue connection}';

    protected $description = 'Drop every job in a queue';

    public function handle(Manager $manager): int
    {
        $queue = (string) $this->argument('queue');
        $connection = $this->option('connection');

        $states = (array) $this->option('state');
        $states = $states === [] ? ['pending', 'delayed'] : $states;

        if (in_array('reserved', $states, true)) {
            $this->components->warn(
                'Purging reserved jobs also drops jobs a worker is executing right now. '.
                'They will finish, but nothing will retry them if the worker dies.'
            );
        }

        $counts = $manager->counts($queue, $connection);
        $about = array_sum(array_intersect_key($counts, array_flip($states)));

        if (! $this->confirmToProceed($queue, $about)) {
            return self::FAILURE;
        }

        $removed = $manager->purge(
            $queue,
            array_map(static fn (string $state): JobState => JobState::make($state), $states),
            $connection,
        );

        $this->components->info("Removed {$removed} job(s) from [{$queue}].");

        return self::SUCCESS;
    }

    private function confirmToProceed(string $queue, int $count): bool
    {
        if ($this->option('no-interaction')) {
            return true;
        }

        return $this->confirm("Drop {$count} job(s) from [{$queue}]?", false);
    }
}
