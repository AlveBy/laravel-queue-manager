<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Console;

use AlveBy\QueueManager\Manager;
use AlveBy\QueueManager\Support\JobRecord;
use Illuminate\Console\Command;

class ListJobsCommand extends Command
{
    protected $signature = 'queue-manager:jobs
                            {--queue= : Only this queue}
                            {--connection= : Only this queue connection}
                            {--state= : pending, delayed or reserved}
                            {--tag=* : Only jobs carrying every given tag (needs the index)}
                            {--limit=25 : How many to show}
                            {--offset=0 : Skip this many}';

    protected $description = 'List jobs currently sitting in a queue';

    public function handle(Manager $manager): int
    {
        $query = $manager->search()
            ->limit((int) $this->option('limit'))
            ->offset((int) $this->option('offset'));

        if ($queue = $this->option('queue')) {
            $query->queue($queue);
        }

        if ($connection = $this->option('connection')) {
            $query->connection($connection);
        }

        if ($state = $this->option('state')) {
            $query->state($state);
        }

        foreach ((array) $this->option('tag') as $tag) {
            $query->tag($tag);
        }

        $jobs = $query->get();

        if ($jobs->isEmpty()) {
            $this->components->info('No jobs matched.');

            return self::SUCCESS;
        }

        $this->table(
            ['UUID', 'Queue', 'Job', 'State', 'Attempts', 'Available at'],
            $jobs->map(static fn (JobRecord $job): array => [
                $job->uuid,
                $job->queue,
                class_basename($job->name),
                $job->state->value,
                $job->attempts,
                $job->availableAtDate()?->toDateTimeString() ?? '',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
