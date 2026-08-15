<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Concerns;

use AlveBy\QueueManager\Contracts\CancellationStore;
use AlveBy\QueueManager\Exceptions\JobWasCancelled;
use Illuminate\Container\Container;

/**
 * Cooperative cancellation for jobs that are already running.
 *
 * A job that is mid-flight cannot be stopped from the outside, so a long job
 * has to check in:
 *
 *     foreach ($rows->chunk(500) as $chunk) {
 *         if ($this->cancelled()) {
 *             return;
 *         }
 *
 *         $this->export($chunk);
 *     }
 *
 * Each check is one Redis round-trip, so poll per chunk rather than per row.
 */
trait Cancellable
{
    public function cancelled(): bool
    {
        $uuid = $this->cancellationUuid();

        if ($uuid === null) {
            return false;
        }

        return Container::getInstance()->make(CancellationStore::class)->isCancelled($uuid);
    }

    /**
     * Unwind out of deeply nested work.
     *
     * The job is deleted first so the worker will not retry it. The exception
     * still reaches your exception handler — add JobWasCancelled to the
     * framework's dontReport list if you would rather not see it there.
     *
     * @throws JobWasCancelled
     */
    public function abortIfCancelled(): void
    {
        if (! $this->cancelled()) {
            return;
        }

        $uuid = (string) $this->cancellationUuid();

        $this->job?->delete();

        throw new JobWasCancelled("Job [{$uuid}] was cancelled.");
    }

    private function cancellationUuid(): ?string
    {
        $job = $this->job ?? null;

        if ($job === null || ! method_exists($job, 'uuid')) {
            return null;
        }

        $uuid = $job->uuid();

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }
}
