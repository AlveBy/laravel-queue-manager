<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Contracts;

/**
 * Remembers which job uuids must not run.
 *
 * The flag has to outlive the job: a job can sit delayed, be released, or be
 * retried long after cancel() was called. Entries expire on a TTL so the
 * store does not grow without bound.
 */
interface CancellationStore
{
    public function cancel(string $uuid, ?string $reason = null, ?int $ttl = null): bool;

    public function isCancelled(string $uuid): bool;

    /**
     * @return array{at: int, reason: string|null}|null
     */
    public function details(string $uuid): ?array;

    public function forget(string $uuid): bool;

    /**
     * Recently cancelled uuids, newest first.
     *
     * @return array<int, array{uuid: string, at: int}>
     */
    public function recent(int $limit = 50): array;

    /**
     * Drop index entries older than the given timestamp (defaults to the TTL).
     */
    public function prune(?int $before = null): int;
}
