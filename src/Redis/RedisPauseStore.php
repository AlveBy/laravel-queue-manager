<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Redis;

use AlveBy\QueueManager\Contracts\PauseStore;
use AlveBy\QueueManager\Support\Keys;
use AlveBy\QueueManager\Support\PausedQueue;
use DateTimeInterface;

/**
 * Pause flags live in a single hash, keyed "{connection}:{queue}".
 *
 * A timed pause stores an expiry inside the value rather than relying on key
 * expiry, because hash fields cannot expire on their own before Redis 7.4.
 * Expired entries are dropped the first time anybody reads them.
 */
final class RedisPauseStore implements PauseStore
{
    /** @var array<string, array{0: float, 1: bool}> */
    private array $cache = [];

    public function __construct(
        private readonly StateConnection $state,
        private readonly ConnectionRegistry $connections,
        private readonly Keys $keys,
        private readonly int $cacheTtl = 0,
    ) {}

    public function pause(
        string $queue,
        ?string $connection = null,
        DateTimeInterface|int|null $until = null,
        ?string $reason = null,
    ): PausedQueue {
        $connection = $this->connection($connection);

        $paused = new PausedQueue(
            connection: $connection,
            queue: $queue,
            pausedAt: time(),
            until: $until instanceof DateTimeInterface ? $until->getTimestamp() : $until,
            reason: $reason,
        );

        $this->state->get()->hset(
            $this->keys->paused(),
            $this->keys->pausedField($connection, $queue),
            (string) json_encode($paused->toArray()),
        );

        $this->forgetCached($connection, $queue);

        return $paused;
    }

    public function resume(string $queue, ?string $connection = null): bool
    {
        $connection = $this->connection($connection);

        $removed = (int) $this->state->get()->hdel(
            $this->keys->paused(),
            $this->keys->pausedField($connection, $queue),
        );

        $this->forgetCached($connection, $queue);

        return $removed > 0;
    }

    public function isPaused(string $queue, ?string $connection = null): bool
    {
        $connection = $this->connection($connection);
        $field = $this->keys->pausedField($connection, $queue);

        if ($this->cacheTtl > 0
            && isset($this->cache[$field])
            && $this->cache[$field][0] > microtime(true)) {
            return $this->cache[$field][1];
        }

        $paused = $this->get($queue, $connection) !== null;

        if ($this->cacheTtl > 0) {
            $this->cache[$field] = [microtime(true) + $this->cacheTtl, $paused];
        }

        return $paused;
    }

    public function get(string $queue, ?string $connection = null): ?PausedQueue
    {
        $connection = $this->connection($connection);
        $field = $this->keys->pausedField($connection, $queue);

        $raw = $this->state->get()->hget($this->keys->paused(), $field);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $paused = $this->hydrate($connection, $queue, $raw);

        if ($paused === null) {
            return null;
        }

        if ($paused->isExpired()) {
            $this->state->get()->hdel($this->keys->paused(), $field);

            return null;
        }

        return $paused;
    }

    public function all(): array
    {
        $entries = (array) $this->state->get()->hgetall($this->keys->paused());

        $paused = [];
        $expired = [];

        foreach ($entries as $field => $raw) {
            [$connection, $queue] = array_pad(explode(':', (string) $field, 2), 2, '');

            if ($queue === '') {
                continue;
            }

            $record = $this->hydrate($connection, $queue, (string) $raw);

            if ($record === null) {
                continue;
            }

            if ($record->isExpired()) {
                $expired[] = $field;

                continue;
            }

            $paused[] = $record;
        }

        if ($expired !== []) {
            $this->state->get()->hdel($this->keys->paused(), ...$expired);
        }

        return $paused;
    }

    public function resumeAll(): int
    {
        $count = count($this->all());

        $this->state->get()->del($this->keys->paused());

        $this->cache = [];

        return $count;
    }

    private function hydrate(string $connection, string $queue, string $raw): ?PausedQueue
    {
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return null;
        }

        return new PausedQueue(
            connection: $connection,
            queue: $queue,
            pausedAt: (int) ($data['paused_at'] ?? time()),
            until: isset($data['until']) ? (int) $data['until'] : null,
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
        );
    }

    private function connection(?string $connection): string
    {
        return $connection ?? $this->connections->defaultName();
    }

    private function forgetCached(string $connection, string $queue): void
    {
        unset($this->cache[$this->keys->pausedField($connection, $queue)]);
    }
}
