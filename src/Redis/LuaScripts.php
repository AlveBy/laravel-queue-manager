<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Redis;

/**
 * The atomic bits of queue surgery.
 *
 * Illuminate\Queue\RedisQueue keeps a "notify" list alongside every queue: a
 * push RPUSHes a token onto it and a pop LPOPs one, so workers can block on
 * BLPOP instead of polling. Removing a job from the queue list without also
 * consuming its notify token leaves the list permanently out of sync and
 * wakes workers up for jobs that are not there. Every script below keeps the
 * two in step.
 */
final class LuaScripts
{
    /**
     * Remove one exact payload from a queue list.
     *
     * KEYS[1] - queue list
     * KEYS[2] - notify list
     * ARGV[1] - the exact payload string
     *
     * Returns 1 when removed, 0 when it was already gone.
     */
    public static function removeFromPending(): string
    {
        return <<<'LUA'
if redis.call('lrem', KEYS[1], 1, ARGV[1]) > 0 then
    redis.call('lpop', KEYS[2])
    return 1
end

return 0
LUA;
    }

    /**
     * Move a delayed job into the ready list right now.
     *
     * KEYS[1] - delayed sorted set
     * KEYS[2] - queue list
     * KEYS[3] - notify list
     * ARGV[1] - the exact payload string
     */
    public static function promoteDelayed(): string
    {
        return <<<'LUA'
if redis.call('zrem', KEYS[1], ARGV[1]) > 0 then
    redis.call('rpush', KEYS[2], ARGV[1])
    redis.call('rpush', KEYS[3], 1)
    return 1
end

return 0
LUA;
    }

    /**
     * Take a job out of the ready list and park it in the delayed set.
     *
     * KEYS[1] - queue list
     * KEYS[2] - notify list
     * KEYS[3] - delayed sorted set
     * ARGV[1] - the exact payload string
     * ARGV[2] - new availability timestamp
     */
    public static function delayPending(): string
    {
        return <<<'LUA'
if redis.call('lrem', KEYS[1], 1, ARGV[1]) > 0 then
    redis.call('lpop', KEYS[2])
    redis.call('zadd', KEYS[3], ARGV[2], ARGV[1])
    return 1
end

return 0
LUA;
    }

    /**
     * Change the availability of a job that is already delayed. Uses XX so a
     * job a worker just migrated is not resurrected into the delayed set.
     *
     * KEYS[1] - delayed sorted set
     * ARGV[1] - new availability timestamp
     * ARGV[2] - the exact payload string
     */
    public static function rescheduleDelayed(): string
    {
        return <<<'LUA'
if redis.call('zscore', KEYS[1], ARGV[2]) then
    redis.call('zadd', KEYS[1], 'XX', ARGV[1], ARGV[2])
    return 1
end

return 0
LUA;
    }

    /**
     * Empty a queue list and its notify list together.
     *
     * KEYS[1] - queue list
     * KEYS[2] - notify list
     *
     * Returns how many jobs were dropped.
     */
    public static function purgePending(): string
    {
        return <<<'LUA'
local size = redis.call('llen', KEYS[1])

redis.call('del', KEYS[1])
redis.call('del', KEYS[2])

return size
LUA;
    }
}
