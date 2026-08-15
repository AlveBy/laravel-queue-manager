<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Queue;

use AlveBy\QueueManager\Contracts\PauseStore;
use AlveBy\QueueManager\Redis\ConnectionRegistry;
use AlveBy\QueueManager\Support\PausedQueue;
use DateTimeInterface;

/**
 * Keeps this package's pause flag and the framework's own in step.
 *
 * Pausing writes both, so a worker stops on whichever mechanism it consults.
 * Resuming clears both, so a queue paused with `queue:pause` can be released
 * through this package and vice versa. isPaused() is the union: if either
 * side says paused, the queue is paused.
 *
 * The pausable connector deliberately reads the inner store directly rather
 * than going through here — the framework has usually made its own check by
 * the time pop() is reached, and a second cache read per poll would buy
 * nothing.
 */
final class CompositePauseStore implements PauseStore
{
    public function __construct(
        private readonly PauseStore $inner,
        private readonly NativePauseBridge $native,
        private readonly ConnectionRegistry $connections,
    ) {}

    public function pause(
        string $queue,
        ?string $connection = null,
        DateTimeInterface|int|null $until = null,
        ?string $reason = null,
    ): PausedQueue {
        $paused = $this->inner->pause($queue, $this->resolve($connection), $until, $reason);

        $this->native->pause($paused->connection, $paused->queue, $paused->until);

        return $paused;
    }

    public function resume(string $queue, ?string $connection = null): bool
    {
        $connection = $this->resolve($connection);

        $wasPausedNatively = $this->native->isPaused($connection, $queue);

        $this->native->resume($connection, $queue);

        return $this->inner->resume($queue, $connection) || $wasPausedNatively;
    }

    public function isPaused(string $queue, ?string $connection = null): bool
    {
        $connection = $this->resolve($connection);

        return $this->inner->isPaused($queue, $connection)
            || $this->native->isPaused($connection, $queue);
    }

    public function get(string $queue, ?string $connection = null): ?PausedQueue
    {
        $connection = $this->resolve($connection);

        if ($record = $this->inner->get($queue, $connection)) {
            return $record;
        }

        if (! $this->native->isPaused($connection, $queue)) {
            return null;
        }

        // Paused through `queue:pause`, so we have no metadata of our own.
        return new PausedQueue(
            connection: $connection,
            queue: $queue,
            pausedAt: 0,
            until: null,
            reason: 'paused outside laravel-queue-manager',
        );
    }

    public function all(): array
    {
        return $this->inner->all();
    }

    public function resumeAll(): int
    {
        foreach ($this->inner->all() as $record) {
            $this->native->resume($record->connection, $record->queue);
        }

        return $this->inner->resumeAll();
    }

    private function resolve(?string $connection): string
    {
        return $connection ?? $this->connections->defaultName();
    }
}
