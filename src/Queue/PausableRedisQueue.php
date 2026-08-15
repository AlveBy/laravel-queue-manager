<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Queue;

use Illuminate\Queue\RedisQueue;

class PausableRedisQueue extends RedisQueue
{
    use SkipsPausedQueues;
}
