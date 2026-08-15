<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Console;

use AlveBy\QueueManager\Manager;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'queue-manager:prune
                            {--limit=1000 : How many index entries to inspect}';

    protected $description = 'Drop stale index and cancellation entries';

    public function handle(Manager $manager): int
    {
        $pruned = $manager->prune((int) $this->option('limit'));

        $this->components->twoColumnDetail('Index entries removed', (string) $pruned['index']);
        $this->components->twoColumnDetail('Cancellation entries removed', (string) $pruned['cancellations']);

        return self::SUCCESS;
    }
}
