<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Queue\Horizon;

use AlveBy\QueueManager\Queue\SkipsPausedQueues;
use Laravel\Horizon\RedisQueue;

/**
 * Horizon ships its own RedisQueue subclass (it stamps payloads and fires
 * Horizon's events), so pausing has to extend that one instead of the
 * framework's. This file is only ever autoloaded when Horizon is installed.
 */
class PausableHorizonRedisQueue extends RedisQueue
{
    use SkipsPausedQueues;
}
