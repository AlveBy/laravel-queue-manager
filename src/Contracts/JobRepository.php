<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Contracts;

use AlveBy\QueueManager\Support\JobRecord;
use AlveBy\QueueManager\Support\JobState;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Direct access to the jobs sitting in a queue backend.
 *
 * Implementations work against the real queue structures, not a mirror, so
 * everything here is authoritative but costs a scan when no queue is given.
 */
interface JobRepository
{
    public function find(string $uuid, ?string $queue = null, ?string $connection = null): ?JobRecord;

    /**
     * Remove a job from the queue. Returns the record that was removed, or
     * null if it was not there (or a worker grabbed it first).
     *
     * @param  array<int, JobState>|null  $states  Limit which structures are
     *                                             searched. Null means all
     *                                             three.
     */
    public function delete(
        string $uuid,
        ?string $queue = null,
        ?string $connection = null,
        ?array $states = null,
    ): ?JobRecord;

    /**
     * @return Collection<int, JobRecord>
     */
    public function list(
        string $queue,
        JobState $state,
        int $limit = 50,
        int $offset = 0,
        ?string $connection = null,
    ): Collection;

    /**
     * @return array{pending: int, delayed: int, reserved: int}
     */
    public function counts(string $queue, ?string $connection = null): array;

    /**
     * Queue names known for a connection.
     *
     * @return array<int, string>
     */
    public function queues(?string $connection = null): array;

    /**
     * Drop every job in the given states. Returns how many were dropped.
     *
     * @param  array<int, JobState>  $states
     */
    public function purge(string $queue, array $states, ?string $connection = null): int;

    /**
     * Promote a delayed job so it becomes available immediately.
     */
    public function moveToReady(string $uuid, ?string $queue = null, ?string $connection = null): ?JobRecord;

    /**
     * Move a pending or delayed job to a new availability time.
     */
    public function reschedule(
        string $uuid,
        DateTimeInterface|int $when,
        ?string $queue = null,
        ?string $connection = null,
    ): ?JobRecord;
}
