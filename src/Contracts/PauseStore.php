<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Contracts;

use AlveBy\QueueManager\Support\PausedQueue;
use DateTimeInterface;

/**
 * Holds the "this queue is paused" flags that workers consult before popping.
 */
interface PauseStore
{
    public function pause(
        string $queue,
        ?string $connection = null,
        DateTimeInterface|int|null $until = null,
        ?string $reason = null,
    ): PausedQueue;

    public function resume(string $queue, ?string $connection = null): bool;

    public function isPaused(string $queue, ?string $connection = null): bool;

    public function get(string $queue, ?string $connection = null): ?PausedQueue;

    /**
     * @return array<int, PausedQueue>
     */
    public function all(): array;

    public function resumeAll(): int;
}
