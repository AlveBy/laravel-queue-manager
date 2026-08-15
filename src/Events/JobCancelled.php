<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Events;

use AlveBy\QueueManager\Support\JobRecord;

/**
 * Fired by QueueManager::cancel(), before the worker ever sees the job.
 */
final class JobCancelled
{
    public function __construct(
        public readonly string $uuid,
        public readonly ?string $reason = null,
        /** Whether the job was still queued and got removed outright. */
        public readonly bool $removed = false,
        public readonly ?JobRecord $job = null,
    ) {}
}
