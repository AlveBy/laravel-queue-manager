<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Console;

use AlveBy\QueueManager\Manager;
use Illuminate\Console\Command;

class DeleteJobCommand extends Command
{
    protected $signature = 'queue-manager:delete
                            {uuid* : One or more job uuids}
                            {--queue= : Where to look, skips the scan}
                            {--connection= : Which queue connection to look on}';

    protected $description = 'Remove jobs from a queue without flagging them cancelled';

    public function handle(Manager $manager): int
    {
        $missing = 0;
        $rows = [];

        foreach ((array) $this->argument('uuid') as $uuid) {
            $record = $manager->delete(
                (string) $uuid,
                $this->option('queue'),
                $this->option('connection'),
            );

            if ($record === null) {
                $missing++;
            }

            $rows[] = [
                (string) $uuid,
                $record?->queue ?? '',
                $record === null ? 'not found' : 'removed from '.$record->state->value,
            ];
        }

        $this->table(['UUID', 'Queue', 'Outcome'], $rows);

        return $missing > 0 ? self::FAILURE : self::SUCCESS;
    }
}
