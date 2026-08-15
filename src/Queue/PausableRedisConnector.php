<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Queue;

use Illuminate\Queue\Connectors\RedisConnector;

class PausableRedisConnector extends RedisConnector
{
    /**
     * @param  array<string, mixed>  $config
     * @return \Illuminate\Contracts\Queue\Queue
     */
    public function connect(array $config)
    {
        // Positional, mirroring RedisConnector::connect. PHP ignores extra
        // arguments to userland functions, so older Laravel versions that take
        // fewer constructor parameters are fine; newer ones that add
        // parameters simply get their defaults.
        return new PausableRedisQueue(
            $this->redis,
            $config['queue'],
            $config['connection'] ?? $this->connection,
            $config['retry_after'] ?? 60,
            $config['block_for'] ?? null,
            $config['after_commit'] ?? null,
            $config['migration_batch_size'] ?? -1,
        );
    }
}
