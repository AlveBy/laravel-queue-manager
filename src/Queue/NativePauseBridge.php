<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Queue;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Talks to the queue pausing that Laravel itself gained in 12.x.
 *
 * Illuminate\Queue\QueueManager::pause() writes a cache flag that
 * Worker::getNextJob() consults before popping, and `queue:pause` /
 * `queue:resume` drive the same flag. When that machinery exists we mirror
 * every pause into it, so this package and the framework's own commands
 * always agree on whether a queue is running.
 *
 * The pausable connector stays in place regardless: it still covers the cases
 * the native check does not, such as an unavailable cache store or a worker
 * running with polling disabled.
 */
final class NativePauseBridge
{
    public function __construct(private readonly Container $container) {}

    /**
     * Whether this Laravel version can pause queues on its own.
     */
    public function supported(): bool
    {
        try {
            $manager = $this->container->make('queue');
        } catch (Throwable) {
            return false;
        }

        return method_exists($manager, 'pause')
            && method_exists($manager, 'resume')
            && method_exists($manager, 'isPaused');
    }

    public function pause(string $connection, string $queue, ?int $until = null): bool
    {
        return $this->call(function ($manager) use ($connection, $queue, $until): void {
            if ($until === null) {
                $manager->pause($connection, $queue);

                return;
            }

            $manager->pauseFor($connection, $queue, CarbonImmutable::createFromTimestamp($until));
        });
    }

    public function resume(string $connection, string $queue): bool
    {
        return $this->call(static function ($manager) use ($connection, $queue): void {
            $manager->resume($connection, $queue);
        });
    }

    public function isPaused(string $connection, string $queue): bool
    {
        if (! $this->supported()) {
            return false;
        }

        try {
            return (bool) $this->container->make('queue')->isPaused($connection, $queue);
        } catch (Throwable) {
            return false;
        }
    }

    private function call(callable $callback): bool
    {
        if (! $this->supported()) {
            return false;
        }

        try {
            $callback($this->container->make('queue'));

            return true;
        } catch (Throwable) {
            // A misconfigured cache store must not stop us from writing our
            // own flag, which the pausable connector reads.
            return false;
        }
    }
}
