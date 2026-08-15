<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Redis;

use AlveBy\QueueManager\Contracts\JobIndex;
use AlveBy\QueueManager\Support\JobRecord;
use AlveBy\QueueManager\Support\JobState;
use Illuminate\Support\Collection;

/**
 * Used when queue-manager.index.enabled is false. Everything still works,
 * lookups just fall back to scanning the real queue structures and searching
 * by tag is unavailable.
 */
final class NullJobIndex implements JobIndex
{
    public function enabled(): bool
    {
        return false;
    }

    public function record(string $uuid, array $tags, array $attributes): void {}

    public function markState(string $uuid, JobState $state): void {}

    public function forget(string $uuid): void {}

    public function get(string $uuid): ?JobRecord
    {
        return null;
    }

    public function uuidsForTags(array $tags): array
    {
        return [];
    }

    public function search(array $tags, int $limit = 50, int $offset = 0): Collection
    {
        return Collection::make();
    }

    public function rememberQueue(string $connection, string $queue): void {}

    public function knownQueues(): array
    {
        return [];
    }

    public function prune(int $limit = 1000): int
    {
        return 0;
    }
}
