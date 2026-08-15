<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Listeners;

use AlveBy\QueueManager\Contracts\CancellationStore;
use AlveBy\QueueManager\Events\CancelledJobSkipped;
use AlveBy\QueueManager\Support\Payload;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Drops cancelled jobs the moment a worker picks them up.
 *
 * Worker::process() dispatches JobProcessing, then checks $job->isDeleted()
 * and returns before calling fire(), so deleting the job here is enough to
 * stop it running under every queue driver and for every kind of job.
 *
 * The tradeoff of hooking this early is that CallQueuedHandler never runs, so
 * the bookkeeping it normally does is skipped:
 *
 *   - Batches: handled below, by telling the batch the job is accounted for.
 *   - Chains: deliberately not handled. Cancelling a chained job cancels the
 *     rest of its chain, which is what "cancel" should mean.
 *
 * Use the SkipIfCancelled middleware instead if you want the framework's own
 * skip path, where chains continue.
 */
final class SkipCancelledJobs
{
    public function __construct(
        private readonly CancellationStore $store,
        private readonly Events $events,
        private readonly bool $recordBatchProgress = true,
    ) {}

    public function handle(JobProcessing $event): void
    {
        $job = $event->job;
        $uuid = $job->uuid();

        if (! is_string($uuid) || $uuid === '' || ! $this->store->isCancelled($uuid)) {
            return;
        }

        if ($this->recordBatchProgress) {
            $this->recordBatchProgress((array) $job->payload(), $uuid);
        }

        $job->delete();

        $this->events->dispatch(new CancelledJobSkipped(
            connectionName: $event->connectionName,
            job: $job,
            uuid: $uuid,
            reason: $this->store->details($uuid)['reason'] ?? null,
        ));
    }

    /**
     * Keep a batch's pending counter honest so the batch can still finish.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordBatchProgress(array $payload, string $uuid): void
    {
        $batchId = Payload::batchId($payload);

        if ($batchId === null) {
            return;
        }

        try {
            Bus::findBatch($batchId)?->recordSuccessfulJob($uuid);
        } catch (Throwable) {
            // A missing or already-finished batch is not a reason to let a
            // cancelled job run.
        }
    }
}
