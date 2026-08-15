<?php

declare(strict_types=1);

namespace AlveBy\QueueManager;

use AlveBy\QueueManager\Contracts\JobIndex;
use AlveBy\QueueManager\Contracts\JobRepository;
use AlveBy\QueueManager\Exceptions\QueueManagerException;
use AlveBy\QueueManager\Redis\ConnectionRegistry;
use AlveBy\QueueManager\Support\JobRecord;
use AlveBy\QueueManager\Support\JobState;
use AlveBy\QueueManager\Support\TagResolver;
use Illuminate\Support\Collection;

/**
 * Fluent lookup over queued jobs.
 *
 *     QueueManager::search()->tag('client:123')->get();
 *     QueueManager::search()->queue('export')->state('delayed')->limit(20)->get();
 *
 * Two very different engines sit behind this:
 *
 *   - With tags, the query is served from the index. Fast, but it only knows
 *     about jobs queued since the package was installed and only about jobs
 *     that have not finished.
 *   - Without tags, the real Redis structures are walked. Always accurate,
 *     but O(n) in queue length.
 */
final class JobQuery
{
    /** @var array<int, string> */
    private array $tags = [];

    private ?string $queue = null;

    private ?string $connection = null;

    private ?JobState $state = null;

    private int $limit = 50;

    private int $offset = 0;

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobIndex $index,
        private readonly ConnectionRegistry $connections,
    ) {}

    public function tag(string ...$tags): self
    {
        foreach ($tags as $tag) {
            $this->tags[] = $tag;
        }

        return $this;
    }

    public function forClass(string $class): self
    {
        return $this->tag(TagResolver::forClass($class));
    }

    public function queue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    public function connection(string $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    public function state(JobState|string $state): self
    {
        $this->state = JobState::make($state);

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(1, $limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    /**
     * @return Collection<int, JobRecord>
     */
    public function get(): Collection
    {
        return $this->tags === [] ? $this->scan() : $this->fromIndex();
    }

    public function first(): ?JobRecord
    {
        return $this->get()->first();
    }

    public function count(): int
    {
        if ($this->tags !== []) {
            return $this->matching($this->index->search($this->tags, PHP_INT_MAX, 0))->count();
        }

        $states = $this->states();
        $total = 0;

        foreach ($this->targets() as [$connection, $queue]) {
            $counts = $this->jobs->counts($queue, $connection);

            foreach ($states as $state) {
                $total += (int) ($counts[$state->value] ?? 0);
            }
        }

        return $total;
    }

    /**
     * @return Collection<int, JobRecord>
     */
    private function fromIndex(): Collection
    {
        if (! $this->index->enabled()) {
            throw new QueueManagerException(
                'Searching by tag needs the job index, but queue-manager.index.enabled is false. '.
                'Enable it, or query by queue and state instead.'
            );
        }

        return $this->matching($this->index->search($this->tags, PHP_INT_MAX, 0))
            ->slice($this->offset, $this->limit)
            ->values();
    }

    /**
     * @return Collection<int, JobRecord>
     */
    private function scan(): Collection
    {
        $needed = $this->offset + $this->limit;
        $results = Collection::make();

        foreach ($this->targets() as [$connection, $queue]) {
            foreach ($this->states() as $state) {
                $results = $results->concat(
                    $this->jobs->list($queue, $state, $needed, 0, $connection)
                );

                if ($results->count() >= $needed) {
                    break 2;
                }
            }
        }

        return $results->slice($this->offset, $this->limit)->values();
    }

    /**
     * @param  Collection<int, JobRecord>  $records
     * @return Collection<int, JobRecord>
     */
    private function matching(Collection $records): Collection
    {
        return $records
            ->filter(function (JobRecord $record): bool {
                if ($this->queue !== null && $record->queue !== $this->queue) {
                    return false;
                }

                if ($this->connection !== null && $record->connection !== $this->connection) {
                    return false;
                }

                return ! ($this->state !== null && $record->state !== $this->state);
            })
            ->values();
    }

    /**
     * @return array<int, JobState>
     */
    private function states(): array
    {
        if ($this->state === null) {
            return JobState::queued();
        }

        if (! $this->state->isQueued()) {
            throw new QueueManagerException(
                "State [{$this->state->value}] only exists in the index. ".
                'Add a tag filter to query it, or use one of: pending, delayed, reserved.'
            );
        }

        return [$this->state];
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function targets(): array
    {
        $connections = $this->connection !== null
            ? [$this->connection]
            : $this->connections->names();

        $targets = [];

        foreach ($connections as $connection) {
            $queues = $this->queue !== null ? [$this->queue] : $this->jobs->queues($connection);

            foreach ($queues as $queue) {
                $targets[] = [$connection, $queue];
            }
        }

        return $targets;
    }
}
