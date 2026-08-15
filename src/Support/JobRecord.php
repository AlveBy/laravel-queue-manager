<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * A single job as it currently exists in Redis (or in the index).
 *
 * @implements Arrayable<string, mixed>
 */
final class JobRecord implements Arrayable, JsonSerializable
{
    /**
     * @param  string  $raw  The exact stored string. Required for LREM/ZREM.
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $tags
     */
    public function __construct(
        public readonly string $uuid,
        public readonly ?string $id,
        public readonly string $connection,
        public readonly string $queue,
        public readonly string $name,
        public readonly JobState $state,
        public readonly int $attempts,
        public readonly ?int $availableAt = null,
        public readonly ?int $queuedAt = null,
        public readonly array $payload = [],
        public readonly string $raw = '',
        public readonly array $tags = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(
        array $payload,
        string $raw,
        string $connection,
        string $queue,
        JobState $state,
        ?int $availableAt = null,
        array $tags = [],
    ): self {
        return new self(
            uuid: Payload::uuid($payload) ?? '',
            id: Payload::id($payload),
            connection: $connection,
            queue: $queue,
            name: Payload::displayName($payload),
            state: $state,
            attempts: Payload::attempts($payload),
            availableAt: $availableAt,
            queuedAt: null,
            payload: $payload,
            raw: $raw,
            tags: $tags,
        );
    }

    public function availableAtDate(): ?CarbonImmutable
    {
        return $this->availableAt === null ? null : CarbonImmutable::createFromTimestamp($this->availableAt);
    }

    public function queuedAtDate(): ?CarbonImmutable
    {
        return $this->queuedAt === null ? null : CarbonImmutable::createFromTimestamp($this->queuedAt);
    }

    public function batchId(): ?string
    {
        return Payload::batchId($this->payload);
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    public function with(JobState $state): self
    {
        return new self(
            $this->uuid, $this->id, $this->connection, $this->queue, $this->name,
            $state, $this->attempts, $this->availableAt, $this->queuedAt,
            $this->payload, $this->raw, $this->tags,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'id' => $this->id,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'name' => $this->name,
            'state' => $this->state->value,
            'attempts' => $this->attempts,
            'available_at' => $this->availableAt,
            'queued_at' => $this->queuedAt,
            'tags' => $this->tags,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
