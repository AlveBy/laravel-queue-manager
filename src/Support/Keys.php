<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Support;

use InvalidArgumentException;

/**
 * Builds every Redis key this package touches.
 *
 * Queue keys mirror Illuminate\Queue\RedisQueue exactly. Keys are returned
 * unprefixed: the Redis client applies the connection prefix from
 * config/database.php itself, including for the KEYS array of EVAL.
 */
final class Keys
{
    public function __construct(private readonly string $prefix = 'lqm') {}

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function queue(string $queue): string
    {
        return 'queues:'.$queue;
    }

    public function notify(string $queue): string
    {
        return 'queues:'.$queue.':notify';
    }

    public function delayed(string $queue): string
    {
        return 'queues:'.$queue.':delayed';
    }

    public function reserved(string $queue): string
    {
        return 'queues:'.$queue.':reserved';
    }

    public function forState(string $queue, JobState $state): string
    {
        return match ($state) {
            JobState::Pending => $this->queue($queue),
            JobState::Delayed => $this->delayed($queue),
            JobState::Reserved => $this->reserved($queue),
            default => throw new InvalidArgumentException(
                "State [{$state->value}] does not map to a Redis queue structure."
            ),
        };
    }

    /** Glob used to discover queue names. */
    public function queueDiscoveryPattern(): string
    {
        return 'queues:*';
    }

    /** HASH: "{connection}:{queue}" => json{at,until,reason} */
    public function paused(): string
    {
        return $this->prefix.':paused';
    }

    public function pausedField(string $connection, string $queue): string
    {
        return $connection.':'.$queue;
    }

    /** STRING: json{at,reason} */
    public function cancelled(string $uuid): string
    {
        return $this->prefix.':cancelled:'.$uuid;
    }

    /** ZSET: uuid => cancelled_at */
    public function cancelledIndex(): string
    {
        return $this->prefix.':cancelled';
    }

    /** HASH: one record per indexed job. */
    public function job(string $uuid): string
    {
        return $this->prefix.':job:'.$uuid;
    }

    /** SET: uuids carrying a tag. */
    public function tag(string $tag): string
    {
        return $this->prefix.':tag:'.$tag;
    }

    /** ZSET: uuid => queued_at, for pruning and recency. */
    public function jobs(): string
    {
        return $this->prefix.':jobs';
    }

    /** SET: queue names this package has seen, as "{connection}:{queue}". */
    public function queues(): string
    {
        return $this->prefix.':queues';
    }
}
