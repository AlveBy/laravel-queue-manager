<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Redis;

use AlveBy\QueueManager\Contracts\JobRepository;
use AlveBy\QueueManager\Support\JobRecord;
use AlveBy\QueueManager\Support\JobState;
use AlveBy\QueueManager\Support\Keys;
use AlveBy\QueueManager\Support\Payload;
use DateTimeInterface;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Collection;

/**
 * Reads and edits the queue structures Illuminate\Queue\RedisQueue maintains:
 *
 *   queues:{name}           LIST   payloads waiting for a worker
 *   queues:{name}:delayed   ZSET   payload => timestamp it becomes available
 *   queues:{name}:reserved  ZSET   payload => timestamp it may be retried
 *   queues:{name}:notify    LIST   one token per pending job, for BLPOP
 *
 * Jobs are matched on the payload's uuid rather than on a score: scores are
 * timestamps and several jobs routinely share one.
 */
final class RedisJobRepository implements JobRepository
{
    public function __construct(
        private readonly ConnectionRegistry $connections,
        private readonly Keys $keys,
        private readonly QueueDiscovery $discovery,
        private readonly int $chunk = 1000,
    ) {}

    public function find(string $uuid, ?string $queue = null, ?string $connection = null): ?JobRecord
    {
        foreach ($this->searchSpace($queue, $connection) as [$queueConnection, $name]) {
            foreach (JobState::queued() as $state) {
                if ($record = $this->locate($queueConnection, $name, $state, $uuid)) {
                    return $record;
                }
            }
        }

        return null;
    }

    public function delete(
        string $uuid,
        ?string $queue = null,
        ?string $connection = null,
        ?array $states = null,
    ): ?JobRecord {
        $states = array_values(array_filter(
            $states ?? JobState::queued(),
            static fn (JobState $state): bool => $state->isQueued(),
        ));

        foreach ($this->searchSpace($queue, $connection) as [$queueConnection, $name]) {
            $redis = $this->connections->redis($queueConnection);

            foreach ($states as $state) {
                $record = $this->locate($queueConnection, $name, $state, $uuid);

                if ($record === null) {
                    continue;
                }

                $removed = match ($state) {
                    JobState::Pending => (int) $redis->eval(
                        LuaScripts::removeFromPending(),
                        2,
                        $this->keys->queue($name),
                        $this->keys->notify($name),
                        $record->raw,
                    ),
                    default => (int) $redis->zrem($this->keys->forState($name, $state), $record->raw),
                };

                // Zero means a worker moved the job between our read and our
                // write. Keep looking: it may now be in the reserved set.
                if ($removed > 0) {
                    return $record;
                }
            }
        }

        return null;
    }

    public function list(
        string $queue,
        JobState $state,
        int $limit = 50,
        int $offset = 0,
        ?string $connection = null,
    ): Collection {
        $queueConnection = $this->connections->get($connection);
        $redis = $this->connections->redis($queueConnection);
        $key = $this->keys->forState($queue, $state);

        $raws = $state === JobState::Pending
            ? (array) $redis->lrange($key, $offset, $offset + $limit - 1)
            : (array) $redis->zrange($key, $offset, $offset + $limit - 1);

        $raws = array_values(array_map('strval', $raws));

        $scores = $state === JobState::Pending
            ? []
            : $this->scoresFor($redis, $key, $raws);

        return Collection::make($raws)
            ->map(function (string $raw) use ($queueConnection, $queue, $state, $scores): ?JobRecord {
                $payload = Payload::decode($raw);

                if ($payload === null || Payload::uuid($payload) === null) {
                    return null;
                }

                return JobRecord::fromPayload(
                    payload: $payload,
                    raw: $raw,
                    connection: $queueConnection->name,
                    queue: $queue,
                    state: $state,
                    availableAt: $scores[$raw] ?? null,
                );
            })
            ->filter()
            ->values();
    }

    public function counts(string $queue, ?string $connection = null): array
    {
        $redis = $this->connections->redis($this->connections->get($connection));

        return [
            'pending' => (int) $redis->llen($this->keys->queue($queue)),
            'delayed' => (int) $redis->zcard($this->keys->delayed($queue)),
            'reserved' => (int) $redis->zcard($this->keys->reserved($queue)),
        ];
    }

    public function queues(?string $connection = null): array
    {
        $queueConnection = $this->connections->get($connection);

        return $this->discovery->for($queueConnection, $this->connections->redis($queueConnection));
    }

    public function purge(string $queue, array $states, ?string $connection = null): int
    {
        $redis = $this->connections->redis($this->connections->get($connection));

        $removed = 0;

        foreach ($states as $state) {
            $state = JobState::make($state);

            if ($state === JobState::Pending) {
                $removed += (int) $redis->eval(
                    LuaScripts::purgePending(),
                    2,
                    $this->keys->queue($queue),
                    $this->keys->notify($queue),
                );

                continue;
            }

            $key = $this->keys->forState($queue, $state);
            $removed += (int) $redis->zcard($key);
            $redis->del($key);
        }

        return $removed;
    }

    public function moveToReady(string $uuid, ?string $queue = null, ?string $connection = null): ?JobRecord
    {
        foreach ($this->searchSpace($queue, $connection) as [$queueConnection, $name]) {
            $record = $this->locate($queueConnection, $name, JobState::Delayed, $uuid);

            if ($record === null) {
                continue;
            }

            $promoted = (int) $this->connections->redis($queueConnection)->eval(
                LuaScripts::promoteDelayed(),
                3,
                $this->keys->delayed($name),
                $this->keys->queue($name),
                $this->keys->notify($name),
                $record->raw,
            );

            if ($promoted > 0) {
                return $record->with(JobState::Pending);
            }
        }

        return null;
    }

    public function reschedule(
        string $uuid,
        DateTimeInterface|int $when,
        ?string $queue = null,
        ?string $connection = null,
    ): ?JobRecord {
        $timestamp = $when instanceof DateTimeInterface ? $when->getTimestamp() : $when;

        foreach ($this->searchSpace($queue, $connection) as [$queueConnection, $name]) {
            $redis = $this->connections->redis($queueConnection);

            if ($record = $this->locate($queueConnection, $name, JobState::Delayed, $uuid)) {
                $moved = (int) $redis->eval(
                    LuaScripts::rescheduleDelayed(),
                    1,
                    $this->keys->delayed($name),
                    $timestamp,
                    $record->raw,
                );

                if ($moved > 0) {
                    return $this->rebuild($record, JobState::Delayed, $timestamp);
                }
            }

            if ($record = $this->locate($queueConnection, $name, JobState::Pending, $uuid)) {
                $moved = (int) $redis->eval(
                    LuaScripts::delayPending(),
                    3,
                    $this->keys->queue($name),
                    $this->keys->notify($name),
                    $this->keys->delayed($name),
                    $record->raw,
                    $timestamp,
                );

                if ($moved > 0) {
                    return $this->rebuild($record, JobState::Delayed, $timestamp);
                }
            }
        }

        return null;
    }

    /**
     * Walk one structure looking for a uuid.
     */
    private function locate(
        QueueConnection $connection,
        string $queue,
        JobState $state,
        string $uuid,
    ): ?JobRecord {
        $redis = $this->connections->redis($connection);
        $key = $this->keys->forState($queue, $state);

        $length = $state === JobState::Pending
            ? (int) $redis->llen($key)
            : (int) $redis->zcard($key);

        for ($offset = 0; $offset < $length; $offset += $this->chunk) {
            $stop = $offset + $this->chunk - 1;

            $raws = $state === JobState::Pending
                ? (array) $redis->lrange($key, $offset, $stop)
                : (array) $redis->zrange($key, $offset, $stop);

            foreach ($raws as $raw) {
                $raw = (string) $raw;

                if (! Payload::matches($raw, $uuid)) {
                    continue;
                }

                $payload = Payload::decode($raw) ?? [];

                $score = $state === JobState::Pending
                    ? null
                    : $this->score($redis, $key, $raw);

                return JobRecord::fromPayload(
                    payload: $payload,
                    raw: $raw,
                    connection: $connection->name,
                    queue: $queue,
                    state: $state,
                    availableAt: $score,
                );
            }

            if (count($raws) < $this->chunk) {
                break;
            }
        }

        return null;
    }

    /**
     * Every (connection, queue) pair a lookup should walk.
     *
     * @return iterable<int, array{0: QueueConnection, 1: string}>
     */
    private function searchSpace(?string $queue, ?string $connection): iterable
    {
        $connections = $connection === null
            ? $this->connections->all()
            : [$this->connections->get($connection)];

        foreach ($connections as $queueConnection) {
            $queues = $queue !== null
                ? [$queue]
                : $this->discovery->for($queueConnection, $this->connections->redis($queueConnection));

            foreach ($queues as $name) {
                yield [$queueConnection, $name];
            }
        }
    }

    /**
     * @param  array<int, string>  $members
     * @return array<string, int|null>
     */
    private function scoresFor(RedisConnection $redis, string $key, array $members): array
    {
        if ($members === []) {
            return [];
        }

        $results = $redis->pipeline(function ($pipe) use ($key, $members): void {
            foreach ($members as $member) {
                $pipe->zscore($key, $member);
            }
        });

        $scores = [];

        foreach ($members as $index => $member) {
            $score = $results[$index] ?? null;
            $scores[$member] = is_numeric($score) ? (int) $score : null;
        }

        return $scores;
    }

    private function score(RedisConnection $redis, string $key, string $member): ?int
    {
        $score = $redis->zscore($key, $member);

        return is_numeric($score) ? (int) $score : null;
    }

    private function rebuild(JobRecord $record, JobState $state, ?int $availableAt): JobRecord
    {
        return new JobRecord(
            uuid: $record->uuid,
            id: $record->id,
            connection: $record->connection,
            queue: $record->queue,
            name: $record->name,
            state: $state,
            attempts: $record->attempts,
            availableAt: $availableAt,
            queuedAt: $record->queuedAt,
            payload: $record->payload,
            raw: $record->raw,
            tags: $record->tags,
        );
    }
}
