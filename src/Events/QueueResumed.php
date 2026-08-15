<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Events;

final class QueueResumed
{
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
    ) {}
}
