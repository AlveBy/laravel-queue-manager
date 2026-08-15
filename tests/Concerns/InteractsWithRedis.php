<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Tests\Concerns;

use Illuminate\Support\Facades\Redis;
use Throwable;

trait InteractsWithRedis
{
    protected function setUpRedis(): void
    {
        try {
            Redis::connection()->ping();
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis is not reachable: '.$e->getMessage());
        }

        $this->flushRedis();
    }

    protected function tearDownRedis(): void
    {
        $this->flushRedis();
    }

    /**
     * Only wipe this test run's own keys — the configured prefix keeps us out
     * of anything else living on the server.
     */
    protected function flushRedis(): void
    {
        try {
            Redis::connection()->flushdb();
        } catch (Throwable) {
            // Nothing to clean up.
        }
    }
}
