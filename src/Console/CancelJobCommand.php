<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Console;

use AlveBy\QueueManager\Manager;
use Illuminate\Console\Command;

class CancelJobCommand extends Command
{
    protected $signature = 'queue-manager:cancel
                            {uuid* : One or more job uuids}
                            {--reason= : Recorded with the cancellation}
                            {--queue= : Where to look, skips the scan}
                            {--connection= : Which queue connection to look on}';

    protected $description = 'Stop jobs from running, wherever they currently are';

    public function handle(Manager $manager): int
    {
        $rows = [];

        foreach ((array) $this->argument('uuid') as $uuid) {
            $result = $manager->cancel(
                (string) $uuid,
                $this->option('reason'),
                $this->option('queue'),
                $this->option('connection'),
            );

            $rows[] = [
                $result->uuid,
                $result->state?->value ?? 'not found',
                $result->removed ? 'removed from queue' : 'flagged, worker will drop it',
            ];
        }

        $this->table(['UUID', 'Was', 'Outcome'], $rows);

        $this->components->info(
            'A job that is already executing only stops if it polls Cancellable::cancelled().'
        );

        return self::SUCCESS;
    }
}
