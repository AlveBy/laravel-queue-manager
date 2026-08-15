<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Events;

use Illuminate\Contracts\Queue\Job;

/**
 * Fired by a worker that was about to run a job and dropped it instead.
 *
 * This is the counterpart to JobCancelled: JobCancelled says "somebody asked
 * for this job to stop", this one says "a worker actually stopped it".
 */
final class CancelledJobSkipped
{
    public function __construct(
        public readonly string $connectionName,
        public readonly Job $job,
        public readonly string $uuid,
        public readonly ?string $reason = null,
    ) {}
}
