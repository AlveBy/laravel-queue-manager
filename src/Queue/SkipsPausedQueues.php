<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Queue;

use AlveBy\QueueManager\Redis\RedisPauseStore;
use Illuminate\Container\Container;
use Throwable;

/**
 * Turns pop() into a no-op while the queue is paused.
 *
 * Returning null is exactly what an empty queue looks like to the worker, so
 * `queue:work` keeps running, keeps its --sleep cadence, and picks jobs back
 * up the moment the queue is resumed. Nothing is moved, nothing is lost.
 */
trait SkipsPausedQueues
{
    private ?RedisPauseStore $pauseStore = null;

    /**
     * The $index parameter mirrors RedisQueue::pop(). Passing it through to a
     * parent that does not declare it is harmless — PHP ignores extra
     * arguments to userland functions.
     *
     * @param  string|null  $queue
     * @param  int  $index
     * @return \Illuminate\Contracts\Queue\Job|null
     */
    public function pop($queue = null, $index = 0)
    {
        if ($this->queueIsPaused($queue)) {
            return null;
        }

        return parent::pop($queue, $index);
    }

    /**
     * @param  string|null  $queue
     */
    protected function queueIsPaused($queue): bool
    {
        try {
            // Deliberately the concrete store, not the PauseStore contract:
            // the contract is bound to a composite that also reads the
            // framework's cache flag, which the worker has already checked
            // before it ever reaches pop().
            $store = $this->pauseStore ??= Container::getInstance()->make(RedisPauseStore::class);

            return $store->isPaused(
                (string) ($queue ?: $this->default),
                $this->getConnectionName() ?: null,
            );
        } catch (Throwable) {
            // Never let the pause check take a worker down. If we cannot read
            // the flag we fail open and let the job through.
            return false;
        }
    }
}
