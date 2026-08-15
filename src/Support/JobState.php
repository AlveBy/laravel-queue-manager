<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Support;

enum JobState: string
{
    /** Sitting in the queue list, waiting for a worker. */
    case Pending = 'pending';

    /** In the delayed sorted set, not available yet. */
    case Delayed = 'delayed';

    /**
     * In the reserved sorted set. Either a worker is running it right now, or
     * a worker died holding it and it is waiting for retry_after to elapse.
     * Redis cannot tell those two apart.
     */
    case Reserved = 'reserved';

    /** Index-only: the job finished successfully. */
    case Completed = 'completed';

    /** Index-only: the job exhausted its attempts. */
    case Failed = 'failed';

    /** Index-only: the job was cancelled before it ran. */
    case Cancelled = 'cancelled';

    /**
     * States that map onto a real Redis structure and can be scanned.
     *
     * @return array<int, self>
     */
    public static function queued(): array
    {
        return [self::Pending, self::Delayed, self::Reserved];
    }

    public function isQueued(): bool
    {
        return in_array($this, self::queued(), true);
    }

    /**
     * @param  string|self  $state
     */
    public static function make($state): self
    {
        return $state instanceof self ? $state : self::from($state);
    }
}
