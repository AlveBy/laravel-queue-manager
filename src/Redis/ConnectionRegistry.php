<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Redis;

use AlveBy\QueueManager\Exceptions\UnsupportedConnectionException;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection as RedisConnection;

/**
 * Resolves which queue connections this package manages, and hands out the
 * matching Redis connection for each.
 */
final class ConnectionRegistry
{
    /** @var array<string, QueueConnection>|null */
    private ?array $resolved = null;

    public function __construct(
        private readonly Config $config,
        private readonly RedisFactory $redis,
    ) {}

    /**
     * @return array<string, QueueConnection>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $allowed = $this->config->get('queue-manager.connections');
        $allowed = is_array($allowed) ? $allowed : null;

        $connections = [];

        foreach ((array) $this->config->get('queue.connections', []) as $name => $connectionConfig) {
            if (! is_array($connectionConfig) || ($connectionConfig['driver'] ?? null) !== 'redis') {
                continue;
            }

            if ($allowed !== null && ! in_array($name, $allowed, true)) {
                continue;
            }

            $connections[$name] = QueueConnection::fromConfig((string) $name, $connectionConfig);
        }

        return $this->resolved = $connections;
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->all());
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->all());
    }

    public function get(?string $name = null): QueueConnection
    {
        $name ??= $this->defaultName();

        $connections = $this->all();

        if (! isset($connections[$name])) {
            throw UnsupportedConnectionException::for($name, array_keys($connections));
        }

        return $connections[$name];
    }

    /**
     * The queue connection to use when the caller does not name one: the
     * app's default if it is managed, otherwise the first managed connection.
     */
    public function defaultName(): string
    {
        $default = (string) $this->config->get('queue.default');

        if ($this->has($default)) {
            return $default;
        }

        $names = $this->names();

        if ($names === []) {
            throw UnsupportedConnectionException::none();
        }

        return $names[0];
    }

    public function redis(QueueConnection|string|null $connection = null): RedisConnection
    {
        $connection = $connection instanceof QueueConnection
            ? $connection
            : $this->get($connection);

        /** @var RedisConnection */
        return $this->redis->connection($connection->redisConnection);
    }
}
