<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Events;

use AlveBy\QueueManager\Support\PausedQueue;

final class QueuePaused
{
    public function __construct(public readonly PausedQueue $queue) {}
}
