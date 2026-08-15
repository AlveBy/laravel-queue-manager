<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Managed queue connections
    |--------------------------------------------------------------------------
    |
    | Names from config/queue.php that this package is allowed to manage.
    | Leave null to auto-detect every connection using the "redis" driver.
    |
    */

    'connections' => null,

    /*
    |--------------------------------------------------------------------------
    | Redis key prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for this package's own keys. Note that your Redis connection's
    | own prefix (config/database.php) is still applied on top of this one.
    |
    */

    'prefix' => env('QUEUE_MANAGER_PREFIX', 'lqm'),

    /*
    |--------------------------------------------------------------------------
    | State connection
    |--------------------------------------------------------------------------
    |
    | The Redis connection (from config/database.php) holding this package's
    | own keys. It must be one connection for every worker, whatever queue
    | connection they serve. Null uses the Redis connection of the default
    | managed queue connection.
    |
    */

    'store_connection' => env('QUEUE_MANAGER_STORE_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Scanning
    |--------------------------------------------------------------------------
    |
    | Finding a job by uuid means walking the queue structures. "chunk" is how
    | many entries are pulled per round-trip. "discover_queues" additionally
    | uses SCAN to find queue names that were never seen by this package.
    | "queues" lets you declare extra queue names explicitly.
    |
    */

    'scan' => [
        'chunk' => 1000,
        'discover_queues' => true,
    ],

    'queues' => [],

    /*
    |--------------------------------------------------------------------------
    | Pausing
    |--------------------------------------------------------------------------
    |
    | Pausing swaps the "redis" queue connector for one whose pop() returns
    | null while the queue is paused, so plain `queue:work` keeps running but
    | stops consuming. Set "enabled" to false to leave the connector alone.
    |
    | Laravel 12 pauses queues natively (`queue:pause`, backed by the cache).
    | With "native" => true the two are kept in sync: pausing writes both
    | flags and resuming clears both, so this package and `queue:resume`
    | never disagree. Ignored on Laravel versions without native pausing.
    |
    | "cache_ttl" caches the paused flag in the worker process for N seconds
    | to skip a Redis round-trip per poll. 0 = always read fresh.
    |
    */

    'pause' => [
        'enabled' => true,
        'native' => true,
        'cache_ttl' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cancellation
    |--------------------------------------------------------------------------
    |
    | strategy:
    |   "worker"     - a JobProcessing listener drops cancelled jobs before
    |                  they run. Works for every job, no changes to job classes.
    |   "middleware" - nothing is hooked globally; add the SkipIfCancelled
    |                  middleware to the jobs you want to be cancellable.
    |
    | delete_from_queue: cancel() also removes the job from Redis when it is
    | still pending or delayed, instead of letting a worker pick it up and
    | immediately throw it away.
    |
    | record_batch_progress: when a cancelled job belongs to a bus batch, tell
    | the batch it is done so the batch can still finish. Disable if you would
    | rather cancel the whole batch yourself.
    |
    */

    'cancellation' => [
        'enabled' => true,
        'strategy' => 'worker',
        'ttl' => 86400,
        'delete_from_queue' => true,
        'record_batch_progress' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job index
    |--------------------------------------------------------------------------
    |
    | Mirrors queued jobs into a hash + tag sets so you can search by tag
    | (client id, tenant, class, ...) without scanning the queues. Jobs are
    | tagged automatically, and a job may add its own via the Taggable
    | contract. Disable if you only ever look jobs up by uuid.
    |
    */

    'index' => [
        'enabled' => true,
        'ttl' => 604800,
        'completed_ttl' => 300,
        'failed_ttl' => 86400,
        'auto_tags' => ['class', 'queue', 'connection'],
    ],

];
