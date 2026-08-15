<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Redis;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection as RedisConnection;

/**
 * The one Redis connection this package keeps its own state on: pause flags,
 * cancellation flags and the job index.
 *
 * It has to be a single connection so that a worker on any queue connection
 * reads the same flags. Defaults to whatever Redis connection the default
 * managed queue connection uses.
 */
final class StateConnection
{
    public function __construct(
        private readonly RedisFactory $redis,
        private readonly ConnectionRegistry $connections,
        private readonly ?string $name = null,
    ) {}

    public function get(): RedisConnection
    {
        if ($this->name !== null) {
            /** @var RedisConnection */
            return $this->redis->connection($this->name);
        }

        return $this->connections->redis();
    }
}
