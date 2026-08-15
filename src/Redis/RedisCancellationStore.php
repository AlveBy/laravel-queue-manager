<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Redis;

use AlveBy\QueueManager\Contracts\CancellationStore;
use AlveBy\QueueManager\Support\Keys;

/**
 * One key per cancelled uuid, plus a sorted set for recency and pruning.
 *
 * The TTL matters: a job that is delayed for longer than the TTL will no
 * longer see its own cancellation flag when it finally runs.
 */
final class RedisCancellationStore implements CancellationStore
{
    public function __construct(
        private readonly StateConnection $state,
        private readonly Keys $keys,
        private readonly int $ttl = 86400,
    ) {}

    public function cancel(string $uuid, ?string $reason = null, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->ttl;
        $now = time();

        $this->state->get()->pipeline(function ($pipe) use ($uuid, $reason, $ttl, $now): void {
            $pipe->setex(
                $this->keys->cancelled($uuid),
                max(1, $ttl),
                (string) json_encode(['at' => $now, 'reason' => $reason]),
            );

            $pipe->zadd($this->keys->cancelledIndex(), $now, $uuid);
        });

        return true;
    }

    public function isCancelled(string $uuid): bool
    {
        return (bool) $this->state->get()->exists($this->keys->cancelled($uuid));
    }

    public function details(string $uuid): ?array
    {
        $raw = $this->state->get()->get($this->keys->cancelled($uuid));

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return null;
        }

        return [
            'at' => (int) ($data['at'] ?? 0),
            'reason' => isset($data['reason']) ? (string) $data['reason'] : null,
        ];
    }

    public function forget(string $uuid): bool
    {
        $removed = (int) $this->state->get()->del($this->keys->cancelled($uuid));

        $this->state->get()->zrem($this->keys->cancelledIndex(), $uuid);

        return $removed > 0;
    }

    public function recent(int $limit = 50): array
    {
        $members = (array) $this->state->get()->zrevrange(
            $this->keys->cancelledIndex(),
            0,
            max(0, $limit - 1),
        );

        if ($members === []) {
            return [];
        }

        $scores = $this->state->get()->pipeline(function ($pipe) use ($members): void {
            foreach ($members as $member) {
                $pipe->zscore($this->keys->cancelledIndex(), $member);
            }
        });

        $recent = [];

        foreach (array_values($members) as $index => $member) {
            $recent[] = [
                'uuid' => (string) $member,
                'at' => (int) ($scores[$index] ?? 0),
            ];
        }

        return $recent;
    }

    public function prune(?int $before = null): int
    {
        $before ??= time() - $this->ttl;

        return (int) $this->state->get()->zremrangebyscore(
            $this->keys->cancelledIndex(),
            '-inf',
            (string) $before,
        );
    }
}
