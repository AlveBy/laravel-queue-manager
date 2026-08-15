<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Redis;

use AlveBy\QueueManager\Contracts\JobIndex;
use AlveBy\QueueManager\Support\JobRecord;
use AlveBy\QueueManager\Support\JobState;
use AlveBy\QueueManager\Support\Keys;
use Illuminate\Support\Collection;

/**
 * A small mirror of the jobs currently in flight.
 *
 *   lqm:job:{uuid}   HASH  one record per job, TTL'd
 *   lqm:tag:{tag}    SET   uuids carrying that tag
 *   lqm:jobs         ZSET  uuid => queued_at, for recency and pruning
 *   lqm:queues       SET   "{connection}:{queue}" seen by this package
 *
 * A job leaves the tag sets and the recency set as soon as it reaches a
 * terminal state, so those structures stay proportional to in-flight work
 * rather than to total throughput. The hash lingers on a short TTL so you can
 * still look up what happened to a uuid right after it finished.
 */
final class RedisJobIndex implements JobIndex
{
    private const TERMINAL = [JobState::Completed, JobState::Failed, JobState::Cancelled];

    public function __construct(
        private readonly StateConnection $state,
        private readonly Keys $keys,
        private readonly int $ttl = 604800,
        private readonly int $completedTtl = 300,
        private readonly int $failedTtl = 86400,
    ) {}

    public function enabled(): bool
    {
        return true;
    }

    public function record(string $uuid, array $tags, array $attributes): void
    {
        $now = time();

        $fields = array_filter([
            'uuid' => $uuid,
            'id' => $attributes['id'] ?? null,
            'connection' => $attributes['connection'] ?? null,
            'queue' => $attributes['queue'] ?? null,
            'name' => $attributes['name'] ?? null,
            'state' => ($attributes['state'] ?? JobState::Pending->value),
            'attempts' => (string) ($attributes['attempts'] ?? 0),
            'available_at' => $attributes['available_at'] ?? null,
            'queued_at' => (string) $now,
            'tags' => (string) json_encode(array_values($tags)),
        ], static fn ($value) => $value !== null && $value !== '');

        $this->state->get()->pipeline(function ($pipe) use ($uuid, $tags, $fields, $now): void {
            $pipe->hmset($this->keys->job($uuid), $fields);
            $pipe->expire($this->keys->job($uuid), $this->ttl);
            $pipe->zadd($this->keys->jobs(), $now, $uuid);

            foreach ($tags as $tag) {
                $pipe->sadd($this->keys->tag($tag), $uuid);
                // Safety net for uuids orphaned by a worker that died before
                // reporting a terminal state.
                $pipe->expire($this->keys->tag($tag), $this->ttl);
            }
        });
    }

    public function markState(string $uuid, JobState $state): void
    {
        $terminal = in_array($state, self::TERMINAL, true);

        if (! $terminal) {
            $this->state->get()->hset($this->keys->job($uuid), 'state', $state->value);

            return;
        }

        $tags = $this->tagsFor($uuid);

        $ttl = $state === JobState::Completed ? $this->completedTtl : $this->failedTtl;

        $this->state->get()->pipeline(function ($pipe) use ($uuid, $state, $tags, $ttl): void {
            $pipe->hset($this->keys->job($uuid), 'state', $state->value);
            $pipe->expire($this->keys->job($uuid), max(1, $ttl));
            $pipe->zrem($this->keys->jobs(), $uuid);

            foreach ($tags as $tag) {
                $pipe->srem($this->keys->tag($tag), $uuid);
            }
        });
    }

    public function forget(string $uuid): void
    {
        $tags = $this->tagsFor($uuid);

        $this->state->get()->pipeline(function ($pipe) use ($uuid, $tags): void {
            $pipe->del($this->keys->job($uuid));
            $pipe->zrem($this->keys->jobs(), $uuid);

            foreach ($tags as $tag) {
                $pipe->srem($this->keys->tag($tag), $uuid);
            }
        });
    }

    public function get(string $uuid): ?JobRecord
    {
        $hash = (array) $this->state->get()->hgetall($this->keys->job($uuid));

        return $hash === [] ? null : $this->hydrate($hash);
    }

    public function uuidsForTags(array $tags): array
    {
        if ($tags === []) {
            return array_map('strval', (array) $this->state->get()->zrevrange($this->keys->jobs(), 0, -1));
        }

        $keys = array_map(fn (string $tag): string => $this->keys->tag($tag), array_values($tags));

        return array_map('strval', (array) $this->state->get()->sinter($keys));
    }

    public function search(array $tags, int $limit = 50, int $offset = 0): Collection
    {
        $uuids = $this->uuidsForTags($tags);

        if ($uuids === []) {
            return Collection::make();
        }

        $hashes = $this->state->get()->pipeline(function ($pipe) use ($uuids): void {
            foreach ($uuids as $uuid) {
                $pipe->hgetall($this->keys->job($uuid));
            }
        });

        $records = [];
        $dead = [];

        foreach (array_values($uuids) as $index => $uuid) {
            $hash = (array) ($hashes[$index] ?? []);

            if ($hash === []) {
                $dead[] = $uuid;

                continue;
            }

            $records[] = $this->hydrate($hash);
        }

        $this->forgetDeadUuids($tags, $dead);

        usort(
            $records,
            static fn (JobRecord $a, JobRecord $b): int => ($b->queuedAt ?? 0) <=> ($a->queuedAt ?? 0),
        );

        return Collection::make(array_slice($records, $offset, $limit))->values();
    }

    public function rememberQueue(string $connection, string $queue): void
    {
        $this->state->get()->sadd($this->keys->queues(), $connection.':'.$queue);
    }

    public function knownQueues(): array
    {
        $members = (array) $this->state->get()->smembers($this->keys->queues());

        $queues = [];

        foreach ($members as $member) {
            [$connection, $queue] = array_pad(explode(':', (string) $member, 2), 2, '');

            if ($queue !== '') {
                $queues[] = ['connection' => $connection, 'queue' => $queue];
            }
        }

        return $queues;
    }

    public function prune(int $limit = 1000): int
    {
        $uuids = array_map('strval', (array) $this->state->get()->zrange(
            $this->keys->jobs(),
            0,
            max(0, $limit - 1),
        ));

        if ($uuids === []) {
            return 0;
        }

        $exists = $this->state->get()->pipeline(function ($pipe) use ($uuids): void {
            foreach ($uuids as $uuid) {
                $pipe->exists($this->keys->job($uuid));
            }
        });

        $dead = [];

        foreach ($uuids as $index => $uuid) {
            if (! (bool) ($exists[$index] ?? false)) {
                $dead[] = $uuid;
            }
        }

        if ($dead === []) {
            return 0;
        }

        $this->state->get()->zrem($this->keys->jobs(), ...$dead);

        return count($dead);
    }

    /**
     * Drop uuids whose hash has expired from the tag sets we just read.
     *
     * @param  array<int, string>  $tags
     * @param  array<int, string>  $dead
     */
    private function forgetDeadUuids(array $tags, array $dead): void
    {
        if ($dead === []) {
            return;
        }

        $this->state->get()->pipeline(function ($pipe) use ($tags, $dead): void {
            $pipe->zrem($this->keys->jobs(), ...$dead);

            foreach ($tags as $tag) {
                $pipe->srem($this->keys->tag($tag), ...$dead);
            }
        });
    }

    /**
     * @return array<int, string>
     */
    private function tagsFor(string $uuid): array
    {
        $raw = $this->state->get()->hget($this->keys->job($uuid), 'tags');

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $tags = json_decode($raw, true);

        return is_array($tags) ? array_map('strval', $tags) : [];
    }

    /**
     * @param  array<string, mixed>  $hash
     */
    private function hydrate(array $hash): JobRecord
    {
        $tags = json_decode((string) ($hash['tags'] ?? '[]'), true);

        return new JobRecord(
            uuid: (string) ($hash['uuid'] ?? ''),
            id: isset($hash['id']) ? (string) $hash['id'] : null,
            connection: (string) ($hash['connection'] ?? ''),
            queue: (string) ($hash['queue'] ?? ''),
            name: (string) ($hash['name'] ?? 'unknown'),
            state: JobState::tryFrom((string) ($hash['state'] ?? '')) ?? JobState::Pending,
            attempts: (int) ($hash['attempts'] ?? 0),
            availableAt: isset($hash['available_at']) ? (int) $hash['available_at'] : null,
            queuedAt: isset($hash['queued_at']) ? (int) $hash['queued_at'] : null,
            payload: [],
            raw: '',
            tags: is_array($tags) ? array_map('strval', $tags) : [],
        );
    }
}
