<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Contracts;

use AlveBy\QueueManager\Support\JobRecord;
use AlveBy\QueueManager\Support\JobState;
use Illuminate\Support\Collection;

/**
 * Optional mirror of queued jobs, keyed by uuid and indexed by tag.
 *
 * The index is a convenience, never a source of truth: a job can be missing
 * from it (queued before the package was installed, TTL expired) while still
 * sitting in Redis.
 */
interface JobIndex
{
    public function enabled(): bool;

    /**
     * @param  array<int, string>  $tags
     * @param  array<string, mixed>  $attributes
     */
    public function record(string $uuid, array $tags, array $attributes): void;

    public function markState(string $uuid, JobState $state): void;

    public function forget(string $uuid): void;

    public function get(string $uuid): ?JobRecord;

    /**
     * uuids carrying every one of the given tags.
     *
     * @param  array<int, string>  $tags
     * @return array<int, string>
     */
    public function uuidsForTags(array $tags): array;

    /**
     * @param  array<int, string>  $tags
     * @return Collection<int, JobRecord>
     */
    public function search(array $tags, int $limit = 50, int $offset = 0): Collection;

    /**
     * Register a queue name so it shows up in queue listings.
     */
    public function rememberQueue(string $connection, string $queue): void;

    /**
     * @return array<int, array{connection: string, queue: string}>
     */
    public function knownQueues(): array;

    /**
     * Drop index entries whose job hash is gone. Returns how many were removed.
     */
    public function prune(int $limit = 1000): int;
}
