<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Redis;

/**
 * One entry of config/queue.php that uses the redis driver, resolved.
 */
final class QueueConnection
{
    /**
     * @param  string  $name  Queue connection name, e.g. "redis".
     * @param  string|null  $redisConnection  Redis connection from config/database.php, null = default.
     * @param  array<int, string>  $defaultQueues  Queue names declared in the connection config.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $redisConnection,
        public readonly array $defaultQueues,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        $queues = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($config['queue'] ?? 'default')),
        )));

        return new self(
            name: $name,
            redisConnection: $config['connection'] ?? null,
            defaultQueues: $queues === [] ? ['default'] : $queues,
        );
    }

    public function defaultQueue(): string
    {
        return $this->defaultQueues[0];
    }
}
