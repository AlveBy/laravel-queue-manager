<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Listeners;

use AlveBy\QueueManager\Contracts\JobIndex;
use AlveBy\QueueManager\Events\CancelledJobSkipped;
use AlveBy\QueueManager\Redis\ConnectionRegistry;
use AlveBy\QueueManager\Support\JobState;
use AlveBy\QueueManager\Support\Payload;
use AlveBy\QueueManager\Support\TagResolver;
use DateTimeInterface;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobQueued;
use Throwable;

/**
 * Mirrors queue activity into the job index.
 *
 * JobQueued's constructor has grown across Laravel versions ($payload in 10,
 * $queue in 11, $delay after that), so everything is read defensively rather
 * than positionally.
 */
final class MaintainJobIndex
{
    /**
     * uuids this worker has just cancelled. The framework still fires
     * JobProcessed for a job that was deleted before fire(), and we do not
     * want that to overwrite the cancelled state with "completed".
     *
     * @var array<string, true>
     */
    private array $cancelled = [];

    public function __construct(
        private readonly JobIndex $index,
        private readonly TagResolver $tags,
        private readonly ConnectionRegistry $connections,
    ) {}

    public function handleQueued(JobQueued $event): void
    {
        $connection = (string) $event->connectionName;

        if (! $this->connections->has($connection)) {
            return;
        }

        $payload = $this->payloadOf($event);

        if ($payload === null) {
            return;
        }

        $uuid = Payload::uuid($payload);

        if ($uuid === null) {
            return;
        }

        $queue = $this->queueOf($event, $connection);

        $this->index->rememberQueue($connection, $queue);

        $name = Payload::displayName($payload);

        $this->index->record(
            uuid: $uuid,
            tags: $this->tags->for($event->job, $connection, $queue, $name),
            attributes: [
                'id' => Payload::id($payload) ?? (is_scalar($event->id) ? (string) $event->id : null),
                'connection' => $connection,
                'queue' => $queue,
                'name' => $name,
                'state' => $this->initialState($event)->value,
                'attempts' => Payload::attempts($payload),
                'available_at' => $this->availableAt($event),
            ],
        );
    }

    public function handleProcessed(JobProcessed $event): void
    {
        $uuid = $event->job->uuid();

        if (! is_string($uuid) || $uuid === '') {
            return;
        }

        if (isset($this->cancelled[$uuid])) {
            unset($this->cancelled[$uuid]);

            return;
        }

        $this->index->markState($uuid, JobState::Completed);
    }

    public function handleFailed(JobFailed $event): void
    {
        $uuid = $event->job->uuid();

        if (is_string($uuid) && $uuid !== '') {
            $this->index->markState($uuid, JobState::Failed);
        }
    }

    public function handleCancelled(CancelledJobSkipped $event): void
    {
        $this->cancelled[$event->uuid] = true;

        $this->index->markState($event->uuid, JobState::Cancelled);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payloadOf(JobQueued $event): ?array
    {
        if (method_exists($event, 'payload')) {
            try {
                $payload = $event->payload();

                if (is_array($payload)) {
                    return $payload;
                }
            } catch (Throwable) {
                // Fall through to reading the raw property.
            }
        }

        $raw = $event->payload ?? null;

        if (is_array($raw)) {
            return $raw;
        }

        return is_string($raw) ? Payload::decode($raw) : null;
    }

    private function queueOf(JobQueued $event, string $connection): string
    {
        $queue = $event->queue ?? null;

        if (is_string($queue) && $queue !== '') {
            return $queue;
        }

        $jobQueue = is_object($event->job) ? ($event->job->queue ?? null) : null;

        if (is_string($jobQueue) && $jobQueue !== '') {
            return $jobQueue;
        }

        return $this->connections->get($connection)->defaultQueue();
    }

    private function initialState(JobQueued $event): JobState
    {
        return $this->availableAt($event) === null ? JobState::Pending : JobState::Delayed;
    }

    private function availableAt(JobQueued $event): ?int
    {
        $delay = $event->delay ?? null;

        if ($delay instanceof DateTimeInterface) {
            return $delay->getTimestamp();
        }

        if (is_numeric($delay) && (int) $delay > 0) {
            return time() + (int) $delay;
        }

        return null;
    }
}
