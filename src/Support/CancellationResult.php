<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Support;

use Illuminate\Contracts\Support\Arrayable;

/**
 * What actually happened when you called cancel().
 *
 * @implements Arrayable<string, mixed>
 */
final class CancellationResult implements Arrayable
{
    public function __construct(
        public readonly string $uuid,
        /** The cancellation flag was written (or already existed). */
        public readonly bool $flagged,
        /** The job was physically removed from Redis. */
        public readonly bool $removed,
        /** Where the job was when we looked, null if we never found it. */
        public readonly ?JobState $state = null,
        public readonly ?JobRecord $job = null,
    ) {}

    /**
     * The job is gone for good: it was still queued and we took it out.
     */
    public function wasRemoved(): bool
    {
        return $this->removed;
    }

    /**
     * The job was already reserved, already running, or not found. It will be
     * dropped by the worker instead, assuming the worker sees the flag.
     */
    public function isDeferred(): bool
    {
        return $this->flagged && ! $this->removed;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'flagged' => $this->flagged,
            'removed' => $this->removed,
            'state' => $this->state?->value,
        ];
    }
}
