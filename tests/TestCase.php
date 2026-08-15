<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Tests;

use AlveBy\QueueManager\QueueManagerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [QueueManagerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.redis.client', env('REDIS_CLIENT', 'predis'));
        $app['config']->set('database.redis.options.prefix', 'lqm_test:');
        $app['config']->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD') ?: null,
            'database' => (int) env('REDIS_QUEUE_DB', 15),
        ]);

        $app['config']->set('queue.default', 'redis');
        $app['config']->set('queue.connections.redis', [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'default',
            'retry_after' => 90,
            'block_for' => null,
            'after_commit' => false,
        ]);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue-manager.scan.discover_queues', true);
    }
}
