<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Middleware;

use AlveBy\QueueManager\Contracts\CancellationStore;
use Closure;
use Illuminate\Container\Container;

/**
 * Per-job opt-in cancellation, in the shape of Illuminate's own
 * SkipIfBatchCancelled.
 *
 *     public function middleware(): array
 *     {
 *         return [new SkipIfCancelled];
 *     }
 *
 * Returning without calling $next lets CallQueuedHandler finish normally, so
 * the job is deleted, its batch is credited and the rest of its chain is
 * dispatched — the framework's own definition of "skip".
 *
 * Only needed when queue-manager.cancellation.strategy is "middleware". Under
 * the default "worker" strategy the job is already gone before any middleware
 * runs.
 */
class SkipIfCancelled
{
    /**
     * @param  object  $job
     */
    public function handle($job, Closure $next): mixed
    {
        $uuid = $this->uuidOf($job);

        if ($uuid !== null && $this->store()->isCancelled($uuid)) {
            return null;
        }

        return $next($job);
    }

    /**
     * @param  object  $job
     */
    protected function uuidOf($job): ?string
    {
        // InteractsWithQueue puts the underlying queue job on the command.
        $queueJob = $job->job ?? null;

        if ($queueJob === null || ! method_exists($queueJob, 'uuid')) {
            return null;
        }

        $uuid = $queueJob->uuid();

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    protected function store(): CancellationStore
    {
        return Container::getInstance()->make(CancellationStore::class);
    }
}
