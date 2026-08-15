<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
final class PausedQueue implements Arrayable
{
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly int $pausedAt,
        public readonly ?int $until = null,
        public readonly ?string $reason = null,
    ) {}

    public function isExpired(?int $now = null): bool
    {
        return $this->until !== null && $this->until <= ($now ?? time());
    }

    public function untilDate(): ?CarbonImmutable
    {
        return $this->until === null ? null : CarbonImmutable::createFromTimestamp($this->until);
    }

    public function pausedAtDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp($this->pausedAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'connection' => $this->connection,
            'queue' => $this->queue,
            'paused_at' => $this->pausedAt,
            'until' => $this->until,
            'reason' => $this->reason,
        ];
    }
}
