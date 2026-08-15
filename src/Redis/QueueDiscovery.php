<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Redis;

use AlveBy\QueueManager\Contracts\JobIndex;
use AlveBy\QueueManager\Support\Keys;
use Illuminate\Redis\Connections\Connection as RedisConnection;
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

        return $queues;
    }

    /**
     * SCAN for queues:* and strip the structural suffixes back to queue names.
     *
     * Whether the client prefixes a SCAN MATCH pattern for you depends on the
     * driver, so both forms are tried and the results unioned. The wrong one
     * simply matches nothing.
     *
     * @return array<int, string>
     */
    public function scan(RedisConnection $redis): array
    {
        $prefix = $this->clientPrefix($redis);

        $patterns = array_values(array_unique(array_filter([
            $this->keys->queueDiscoveryPattern(),
            $prefix === '' ? null : $prefix.$this->keys->queueDiscoveryPattern(),
        ])));

        $queues = [];

        foreach ($patterns as $pattern) {
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
        }

        return array_keys($queues);
    }

    /**
     * @return array<int, string>
     */
    private function scanKeys(RedisConnection $redis, string $pattern): array
    {
        $keys = [];
        $cursor = 0;
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
            } while ((int) $cursor !== 0 && ++$guard < 1000);
        } catch (Throwable) {
            // Discovery is best effort; the declared and registered queue
            // names above are always available.
            return [];
        }

        return $keys;
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
