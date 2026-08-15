<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Facades;

use AlveBy\QueueManager\Manager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \AlveBy\QueueManager\Support\JobRecord|null find(string $uuid, ?string $queue = null, ?string $connection = null)
 * @method static \AlveBy\QueueManager\JobQuery search()
 * @method static array<int, string> queues(?string $connection = null)
 * @method static array{pending: int, delayed: int, reserved: int} counts(string $queue, ?string $connection = null)
 * @method static array<int, array{connection: string, queue: string, pending: int, delayed: int, reserved: int, paused: bool}> stats(?string $connection = null)
 * @method static \AlveBy\QueueManager\Support\JobRecord|null delete(string $uuid, ?string $queue = null, ?string $connection = null)
 * @method static \AlveBy\QueueManager\Support\CancellationResult cancel(string $uuid, ?string $reason = null, ?string $queue = null, ?string $connection = null)
 * @method static array<string, \AlveBy\QueueManager\Support\CancellationResult> cancelMany(iterable $uuids, ?string $reason = null, ?string $queue = null)
 * @method static bool isCancelled(string $uuid)
 * @method static bool uncancel(string $uuid)
 * @method static array<int, array{uuid: string, at: int}> recentlyCancelled(int $limit = 50)
 * @method static \AlveBy\QueueManager\Support\JobRecord|null runNow(string $uuid, ?string $queue = null, ?string $connection = null)
 * @method static \AlveBy\QueueManager\Support\JobRecord|null reschedule(string $uuid, \DateTimeInterface|int $when, ?string $queue = null, ?string $connection = null)
 * @method static int purge(string $queue, ?array $states = null, ?string $connection = null)
 * @method static \AlveBy\QueueManager\Support\PausedQueue pause(string $queue, \DateTimeInterface|int|null $until = null, ?string $reason = null, ?string $connection = null)
 * @method static bool resume(string $queue, ?string $connection = null)
 * @method static bool isPaused(string $queue, ?string $connection = null)
 * @method static array<int, \AlveBy\QueueManager\Support\PausedQueue> pausedQueues()
 * @method static int resumeAll()
 * @method static array{index: int, cancellations: int} prune(int $limit = 1000)
 *
 * @see Manager
 */
class QueueManager extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Manager::class;
    }
}
