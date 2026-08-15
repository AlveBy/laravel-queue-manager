<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Console;

use AlveBy\QueueManager\Manager;
use Illuminate\Console\Command;

class ShowJobCommand extends Command
{
    protected $signature = 'queue-manager:show
                            {uuid : The job uuid}
                            {--queue= : Where to look, skips the scan}
                            {--connection= : Which queue connection to look on}
                            {--payload : Dump the raw payload too}';

    protected $description = 'Show everything known about one job';

    public function handle(Manager $manager): int
    {
        $uuid = (string) $this->argument('uuid');

        $job = $manager->find($uuid, $this->option('queue'), $this->option('connection'));

        if ($job === null) {
            $this->components->error("No job found for uuid [{$uuid}].");

            return self::FAILURE;
        }

        $rows = [
            ['uuid', $job->uuid],
            ['id', $job->id ?? ''],
            ['connection', $job->connection],
            ['queue', $job->queue],
            ['job', $job->name],
            ['state', $job->state->value],
            ['attempts', (string) $job->attempts],
            ['queued at', $job->queuedAtDate()?->toDateTimeString() ?? ''],
            ['available at', $job->availableAtDate()?->toDateTimeString() ?? ''],
            ['batch', $job->batchId() ?? ''],
            ['tags', implode(', ', $job->tags)],
            ['cancelled', $manager->isCancelled($job->uuid) ? 'yes' : 'no'],
        ];

        $this->table(['Field', 'Value'], $rows);

        if ($this->option('payload') && $job->payload !== []) {
            $this->newLine();
            $this->line(json_encode($job->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
