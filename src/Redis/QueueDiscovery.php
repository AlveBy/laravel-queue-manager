<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Redis;

use AlveBy\QueueManager\Contracts\JobIndex;
use AlveBy\QueueManager\Support\Keys;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Predis\ClientInterface as PredisClient;
use Throwable;

/**
 * Works out which queue names exist for a connection.
 *
 * Sources, cheapest first:
 *   1. queue names declared in config/queue.php for that connection
 *   2. queue names this package has seen a job pushed to (the index registry)
 *   3. optionally, a SCAN over queues:* — the only way to see queues that
 *      existed before the package was installed
 */
final class QueueDiscovery
{
    /**
     * Discovery walks the keyspace, and job lookups without a queue hint ask
     * for it once per connection. Memoise for the life of the request.
     *
     * @var array<string, array<int, string>>
     */
    private array $cache = [];

    /**
     * @param  array<int, string>  $extraQueues
     */
    public function __construct(
        private readonly JobIndex $index,
        private readonly Keys $keys,
        private readonly bool $scanEnabled = true,
        private readonly array $extraQueues = [],
    ) {}

    /**
     * @return array<int, string>
     */
    public function for(QueueConnection $connection, RedisConnection $redis): array
    {
        if (isset($this->cache[$connection->name])) {
            return $this->cache[$connection->name];
        }

        $queues = $connection->defaultQueues;

        foreach ($this->extraQueues as $queue) {
            $queues[] = $queue;
        }

        foreach ($this->index->knownQueues() as $known) {
            if ($known['connection'] === $connection->name) {
                $queues[] = $known['queue'];
            }
        }

        if ($this->scanEnabled) {
            foreach ($this->scan($redis) as $queue) {
                $queues[] = $queue;
            }
        }

        $queues = array_values(array_unique(array_filter($queues, static fn ($q) => $q !== '')));

        sort($queues);

        return $this->cache[$connection->name] = $queues;
    }

    /**
     * Forget memoised queue names. Long-running processes that create queues
     * on the fly need this; a normal request does not.
     */
    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * SCAN for queues:* and strip the structural suffixes back to queue names.
     *
     * @return array<int, string>
     */
    public function scan(RedisConnection $redis): array
    {
        $prefix = $this->clientPrefix($redis);

        // Neither phpredis nor predis applies the connection prefix to a SCAN
        // MATCH pattern, and neither strips it from the keys that come back,
        // so the prefix goes on by hand and comes off by hand. phpredis will
        // do the prefixing itself if it was explicitly asked to.
        $pattern = $this->prefixesScanPatterns($redis)
            ? $this->keys->queueDiscoveryPattern()
            : $prefix.$this->keys->queueDiscoveryPattern();

        $queues = [];

        foreach ($this->scanKeys($redis, $pattern) as $key) {
            if ($prefix !== '' && str_starts_with($key, $prefix)) {
                $key = substr($key, strlen($prefix));
            }

            if (! str_starts_with($key, 'queues:')) {
                continue;
            }

            $name = substr($key, strlen('queues:'));

            foreach ([':notify', ':delayed', ':reserved'] as $suffix) {
                if (str_ends_with($name, $suffix)) {
                    $name = substr($name, 0, -strlen($suffix));

                    break;
                }
            }

            if ($name !== '') {
                $queues[$name] = true;
            }
        }

        return array_keys($queues);
    }

    /**
     * @return array<int, string>
     */
    private function scanKeys(RedisConnection $redis, string $pattern): array
    {
        $keys = [];
        $cursor = $this->initialCursor($redis);
        $guard = 0;

        try {
            do {
                $result = $redis->scan($cursor, ['match' => $pattern, 'count' => 500]);

                if (! is_array($result)) {
                    break;
                }

                [$cursor, $found] = $result;

                foreach ((array) $found as $key) {
                    $keys[] = (string) $key;
                }
            } while ($cursor !== null && (string) $cursor !== '0' && ++$guard < 10000);
        } catch (Throwable) {
            // Discovery is best effort; the declared and registered queue
            // names are always available without it.
            return [];
        }

        return $keys;
    }

    /**
     * phpredis 6.1+ wants the iterator to start as null and reports the scan
     * as already finished if it is handed a 0. Predis wants '0'. Mirrors what
     * Illuminate\Cache\RedisStore does for the same reason.
     */
    private function initialCursor(RedisConnection $redis): string|int|null
    {
        if ($redis instanceof PhpRedisConnection
            && version_compare((string) phpversion('redis'), '6.1.0', '>=')) {
            return null;
        }

        return '0';
    }

    /**
     * True when the client has been told to apply the connection prefix to
     * SCAN patterns itself (phpredis OPT_SCAN = SCAN_PREFIX).
     */
    private function prefixesScanPatterns(RedisConnection $redis): bool
    {
        try {
            $client = $redis->client();

            if (! defined('Redis::SCAN_PREFIX') || ! method_exists($client, 'getOption')) {
                return false;
            }

            return (int) $client->getOption(\Redis::OPT_SCAN) === \Redis::SCAN_PREFIX;
        } catch (Throwable) {
            return false;
        }
    }

    private function clientPrefix(RedisConnection $redis): string
    {
        try {
            $client = $redis->client();

            if ($client instanceof PredisClient) {
                $options = $client->getOptions();

                if (isset($options->prefix) && method_exists($options->prefix, 'getPrefix')) {
                    return (string) $options->prefix->getPrefix();
                }

                return '';
            }

            if (method_exists($client, 'getOption') && defined('Redis::OPT_PREFIX')) {
                return (string) ($client->getOption(\Redis::OPT_PREFIX) ?: '');
            }
        } catch (Throwable) {
            // fall through
        }

        return '';
    }
}
