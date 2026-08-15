<?php

declare(strict_types=1);

namespace AlveBy\QueueManager;

use AlveBy\QueueManager\Contracts\CancellationStore;
use AlveBy\QueueManager\Contracts\JobIndex;
use AlveBy\QueueManager\Contracts\JobRepository;
use AlveBy\QueueManager\Contracts\PauseStore;
use AlveBy\QueueManager\Events\JobCancelled;
use AlveBy\QueueManager\Events\JobRemoved;
use AlveBy\QueueManager\Events\QueuePaused;
use AlveBy\QueueManager\Events\QueueResumed;
use AlveBy\QueueManager\Redis\ConnectionRegistry;
use AlveBy\QueueManager\Support\CancellationResult;
use AlveBy\QueueManager\Support\JobRecord;
use AlveBy\QueueManager\Support\JobState;
use AlveBy\QueueManager\Support\PausedQueue;
use DateTimeInterface;
use Illuminate\Contracts\Events\Dispatcher as Events;

/**
 * The package's entry point. Reachable as the QueueManager facade.
 */
class Manager
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly PauseStore $pauses,
        private readonly CancellationStore $cancellations,
        private readonly JobIndex $index,
        private readonly ConnectionRegistry $connections,
        private readonly Events $events,
        private readonly bool $deleteOnCancel = true,
    ) {}

    // ---------------------------------------------------------------- lookup

    /**
     * Look a job up by uuid.
     *
     * With no queue given this walks every queue of every managed connection,
     * so pass one when you know it. The index is consulted first for a hint.
     * A job that has already finished is returned from the index, with the
     * state it ended in.
     */
    public function find(string $uuid, ?string $queue = null, ?string $connection = null): ?JobRecord
    {
        $indexed = $this->index->get($uuid);

        if ($queue === null && $indexed !== null && $indexed->queue !== '') {
            $found = $this->jobs->find(
                $uuid,
                $indexed->queue,
                $connection ?? ($indexed->connection !== '' ? $indexed->connection : null),
            );

            if ($found !== null) {
                return $this->withTags($found, $indexed);
            }
        }

        $found = $this->jobs->find($uuid, $queue, $connection);

        if ($found !== null) {
            return $this->withTags($found, $indexed);
        }

        // Not in Redis any more. The index still knows how it ended.
        return $indexed;
    }

    public function search(): JobQuery
    {
        return new JobQuery($this->jobs, $this->index, $this->connections);
    }

    /**
     * @return array<int, string>
     */
    public function queues(?string $connection = null): array
    {
        return $this->jobs->queues($connection);
    }

    /**
     * @return array{pending: int, delayed: int, reserved: int}
     */
    public function counts(string $queue, ?string $connection = null): array
    {
        return $this->jobs->counts($queue, $connection);
    }

    /**
     * One row per queue: counts plus whether it is currently paused.
     *
     * @return array<int, array{connection: string, queue: string, pending: int, delayed: int, reserved: int, paused: bool}>
     */
    public function stats(?string $connection = null): array
    {
        $connections = $connection !== null ? [$connection] : $this->connections->names();

        $rows = [];

        foreach ($connections as $name) {
            foreach ($this->jobs->queues($name) as $queue) {
                $rows[] = [
                    'connection' => $name,
                    'queue' => $queue,
                    ...$this->jobs->counts($queue, $name),
                    'paused' => $this->pauses->isPaused($queue, $name),
                ];
            }
        }

        return $rows;
    }

    // -------------------------------------------------------------- mutation

    /**
     * Take a job out of the queue without marking it cancelled.
     *
     * A job that is already reserved is removed from the reserved set, which
     * does not stop a worker that is running it right now — it only means
     * that job will not be retried if the worker dies. Use cancel() to stop a
     * job that may already be in flight.
     */
    public function delete(string $uuid, ?string $queue = null, ?string $connection = null): ?JobRecord
    {
        $record = $this->jobs->delete($uuid, $queue, $connection);

        if ($record !== null) {
            $this->index->forget($uuid);
            $this->events->dispatch(new JobRemoved($record));
        }

        return $record;
    }

    /**
     * Stop a job from running, wherever it currently is.
     *
     * Two things happen: the uuid is flagged as cancelled so a worker will
     * drop it instead of running it, and — unless
     * queue-manager.cancellation.delete_from_queue is off — the job is
     * removed outright if it is still pending or delayed.
     *
     * Jobs that are already reserved are left in place: a worker may be
     * running one right now, and the flag is what stops it. A job already
     * executing only stops if it polls Cancellable::cancelled().
     *
     * The flag has a TTL (queue-manager.cancellation.ttl). A job delayed for
     * longer than that will no longer see it.
     */
    public function cancel(
        string $uuid,
        ?string $reason = null,
        ?string $queue = null,
        ?string $connection = null,
    ): CancellationResult {
        $this->cancellations->cancel($uuid, $reason);

        $record = null;

        if ($this->deleteOnCancel) {
            $record = $this->jobs->delete($uuid, $queue, $connection, [
                JobState::Pending,
                JobState::Delayed,
            ]);
        }

        $removed = $record !== null;

        if ($record === null) {
            $record = $this->find($uuid, $queue, $connection);
        }

        $this->index->markState($uuid, JobState::Cancelled);

        $this->events->dispatch(new JobCancelled($uuid, $reason, $removed, $record));

        return new CancellationResult(
            uuid: $uuid,
            flagged: true,
            removed: $removed,
            state: $record?->state,
            job: $record,
        );
    }

    /**
     * Cancel several jobs at once. Keyed by uuid.
     *
     * @param  iterable<int, string>  $uuids
     * @return array<string, CancellationResult>
     */
    public function cancelMany(iterable $uuids, ?string $reason = null, ?string $queue = null): array
    {
        $results = [];

        foreach ($uuids as $uuid) {
            $results[$uuid] = $this->cancel($uuid, $reason, $queue);
        }

        return $results;
    }

    public function isCancelled(string $uuid): bool
    {
        return $this->cancellations->isCancelled($uuid);
    }

    /**
     * Drop the cancellation flag. Does not put a removed job back.
     */
    public function uncancel(string $uuid): bool
    {
        return $this->cancellations->forget($uuid);
    }

    /**
     * @return array<int, array{uuid: string, at: int}>
     */
    public function recentlyCancelled(int $limit = 50): array
    {
        return $this->cancellations->recent($limit);
    }

    /**
     * Make a delayed job available immediately.
     */
    public function runNow(string $uuid, ?string $queue = null, ?string $connection = null): ?JobRecord
    {
        return $this->jobs->moveToReady($uuid, $queue, $connection);
    }

    /**
     * Move a pending or delayed job to a new availability time.
     */
    public function reschedule(
        string $uuid,
        DateTimeInterface|int $when,
        ?string $queue = null,
        ?string $connection = null,
    ): ?JobRecord {
        return $this->jobs->reschedule($uuid, $when, $queue, $connection);
    }

    /**
     * Drop every job in a queue. Reserved jobs are left alone unless you ask
     * for them, because a worker is probably holding one.
     *
     * @param  array<int, JobState|string>|null  $states
     */
    public function purge(string $queue, ?array $states = null, ?string $connection = null): int
    {
        $states = array_map(
            static fn ($state) => JobState::make($state),
            $states ?? [JobState::Pending, JobState::Delayed],
        );

        return $this->jobs->purge($queue, $states, $connection);
    }

    // ----------------------------------------------------------------- pause

    /**
     * Stop workers consuming a queue. Jobs keep piling up; nothing is lost.
     */
    public function pause(
        string $queue,
        DateTimeInterface|int|null $until = null,
        ?string $reason = null,
        ?string $connection = null,
    ): PausedQueue {
        $paused = $this->pauses->pause($queue, $connection, $until, $reason);

        $this->events->dispatch(new QueuePaused($paused));

        return $paused;
    }

    public function resume(string $queue, ?string $connection = null): bool
    {
        $resumed = $this->pauses->resume($queue, $connection);

        $this->events->dispatch(new QueueResumed(
            $connection ?? $this->connections->defaultName(),
            $queue,
        ));

        return $resumed;
    }

    public function isPaused(string $queue, ?string $connection = null): bool
    {
        return $this->pauses->isPaused($queue, $connection);
    }

    /**
     * Every paused queue, including ones paused outside this package with
     * `queue:pause`.
     *
     * @return array<int, PausedQueue>
     */
    public function pausedQueues(): array
    {
        $paused = [];

        foreach ($this->pauses->all() as $record) {
            $paused[$record->connection.':'.$record->queue] = $record;
        }

        foreach ($this->connections->names() as $connection) {
            foreach ($this->jobs->queues($connection) as $queue) {
                $key = $connection.':'.$queue;

                if (isset($paused[$key])) {
                    continue;
                }

                if ($record = $this->pauses->get($queue, $connection)) {
                    $paused[$key] = $record;
                }
            }
        }

        return array_values($paused);
    }

    public function resumeAll(): int
    {
        $count = 0;

        foreach ($this->pausedQueues() as $record) {
            if ($this->resume($record->queue, $record->connection)) {
                $count++;
            }
        }

        return $count;
    }

    // ---------------------------------------------------------- housekeeping

    /**
     * @return array{index: int, cancellations: int}
     */
    public function prune(int $limit = 1000): array
    {
        return [
            'index' => $this->index->prune($limit),
            'cancellations' => $this->cancellations->prune(),
        ];
    }

    private function withTags(JobRecord $record, ?JobRecord $indexed): JobRecord
    {
        if ($indexed === null || $indexed->tags === []) {
            return $record;
        }

        return new JobRecord(
            uuid: $record->uuid,
            id: $record->id,
            connection: $record->connection,
            queue: $record->queue,
            name: $record->name,
            state: $record->state,
            attempts: $record->attempts,
            availableAt: $record->availableAt,
            queuedAt: $indexed->queuedAt,
            payload: $record->payload,
            raw: $record->raw,
            tags: $indexed->tags,
        );
    }
}
