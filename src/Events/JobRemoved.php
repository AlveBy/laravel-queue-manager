<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Events;

use AlveBy\QueueManager\Support\JobRecord;

/**
 * A job was taken out of the queue without being cancelled or run.
 */
final class JobRemoved
{
    public function __construct(public readonly JobRecord $job) {}
}
