<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Queue\Horizon;

use Laravel\Horizon\Connectors\RedisConnector;

/**
 * Only autoloaded when Horizon is installed. See PausableHorizonRedisQueue.
 */
class PausableHorizonRedisConnector extends RedisConnector
{
    /**
     * @param  array<string, mixed>  $config
     * @return \Illuminate\Contracts\Queue\Queue
     */
    public function connect(array $config)
    {
        return new PausableHorizonRedisQueue(
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
