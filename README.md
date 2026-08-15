# Laravel Queue Manager

[![Latest version](https://img.shields.io/packagist/v/alveby/laravel-queue-manager.svg?style=flat-square)](https://packagist.org/packages/alveby/laravel-queue-manager)
[![Tests](https://img.shields.io/github/actions/workflow/status/AlveBy/laravel-queue-manager/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/AlveBy/laravel-queue-manager/actions/workflows/tests.yml)
[![Downloads](https://img.shields.io/packagist/dt/alveby/laravel-queue-manager.svg?style=flat-square)](https://packagist.org/packages/alveby/laravel-queue-manager)
[![License](https://img.shields.io/packagist/l/alveby/laravel-queue-manager.svg?style=flat-square)](LICENSE.md)

Cancel, delete, pause, reschedule and inspect Laravel queue jobs from code.

Laravel gives you no way to reach a job once it has been queued. This package
does, for the Redis queue driver, through a small API and a set of Artisan
commands. There is no UI — the assumption is that you are wiring cancellation
into your own application flow.

```php
use AlveBy\QueueManager\Facades\QueueManager;

QueueManager::cancel($uuid, reason: 'user aborted the export');
QueueManager::pause('export', until: now()->addMinutes(10));
QueueManager::search()->tag('client:123')->get();
```

## Requirements

- PHP 8.2+ (8.3+ on Laravel 13)
- Laravel 12 or 13
- A queue connection using the `redis` driver

Laravel 10 and 11 are out of security support and Composer now refuses to
install them, so they are not supported here.

## Installation

```bash
composer require alveby/laravel-queue-manager
```

The provider is auto-discovered. Publish the config if you want to change
anything:

```bash
php artisan vendor:publish --tag=queue-manager-config
```

## Cancelling jobs

`cancel()` does two things: it records the uuid as cancelled, and — if the job
is still pending or delayed — removes it from Redis outright.

```php
$result = QueueManager::cancel($uuid, 'user aborted the export');

$result->wasRemoved();   // true: it was still queued and is now gone
$result->isDeferred();   // true: a worker will drop it instead
$result->state;          // where it was: pending, delayed, reserved, or null
```

What happens depends on where the job was when you called it:

| Job was | Result |
| --- | --- |
| pending | removed from the queue immediately |
| delayed | removed from the delayed set immediately |
| reserved, worker not started | worker drops it before `handle()` |
| **executing right now** | only stops if the job polls (see below) |

A running job cannot be interrupted from outside the process. Give long jobs a
place to check in:

```php
use AlveBy\QueueManager\Concerns\Cancellable;

class ExportJob implements ShouldQueue
{
    use Cancellable;

    public function handle(): void
    {
        foreach ($this->rows()->chunk(500) as $chunk) {
            if ($this->cancelled()) {
                return;
            }

            $this->export($chunk);
        }
    }
}
```

Each `cancelled()` call is one Redis round-trip, so poll per chunk, not per row.
`abortIfCancelled()` is available too — it deletes the job and throws
`JobWasCancelled` so you can unwind out of nested code.

### How the worker drops a job

By default a `JobProcessing` listener deletes cancelled jobs before they run.
`Worker::process()` checks `$job->isDeleted()` immediately after dispatching
that event and returns without calling `fire()`, so this works for every kind
of job — command bus jobs, queued listeners, queued closures and raw
`Queue::push('Class@method')` payloads alike.

The tradeoff is that `CallQueuedHandler` never runs, so the bookkeeping it
normally does is skipped:

- **Batches** are handled. The batch is told the job is accounted for, so the
  batch can still finish. Turn this off with
  `cancellation.record_batch_progress` if you would rather cancel the whole
  batch yourself with `Bus::findBatch($id)->cancel()`.
- **Chains** are deliberately not handled. Cancelling a chained job cancels the
  rest of its chain.

If you want the framework's own skip path instead — where chains continue —
set `cancellation.strategy` to `middleware` and add the middleware to the jobs
you want cancellable:

```php
use AlveBy\QueueManager\Middleware\SkipIfCancelled;

public function middleware(): array
{
    return [new SkipIfCancelled];
}
```

### The cancellation flag expires

Cancellation is a flag in Redis with a TTL (`cancellation.ttl`, 24h by
default). A job delayed for longer than that will no longer see it. Raise the
TTL if you schedule jobs far into the future.

## Pausing queues

Pausing stops workers consuming a queue. Nothing is moved and nothing is lost —
jobs keep piling up and start flowing again the moment you resume.

```php
QueueManager::pause('export');
QueueManager::pause('export', until: now()->addMinutes(10), reason: 'deploying');
QueueManager::isPaused('export');
QueueManager::pausedQueues();
QueueManager::resume('export');
QueueManager::resumeAll();
```

`queue:work` keeps running throughout; it just sees an empty queue. Pausing is
per queue, so `--queue=high,low` with `low` paused keeps draining `high`.

Laravel pauses queues natively too (`queue:pause`, backed by the cache, read by
the worker before it pops). Both flags are written and cleared together, so
this package and the framework's own commands never disagree.

On top of that the package swaps the redis connector for a `RedisQueue`
subclass whose `pop()` returns null while paused. That is the belt to the
framework's braces: it keeps working when the cache store is unavailable, and
when `Queue::disablePolling()` has switched the native check off. Set
`pause.enabled` to `false` to leave the connector alone, or `pause.native` to
`false` to stop writing the framework's flag.

**Horizon** subclasses `RedisQueue`, so the package detects it and extends
Horizon's class instead of the framework's. No configuration needed.

## Finding jobs

By uuid:

```php
$job = QueueManager::find($uuid);

$job->state;        // JobState::Pending | Delayed | Reserved | Completed | Failed | Cancelled
$job->queue;
$job->name;         // App\Jobs\ExportJob
$job->attempts;
$job->availableAtDate();
$job->tags;
```

Without a queue hint this walks every queue of every managed connection, so
pass one when you know it: `QueueManager::find($uuid, 'export')`.

By tag, which is what the index is for:

```php
QueueManager::search()->tag('client:123')->get();
QueueManager::search()->tag('client:123', 'queue:export')->get();
QueueManager::search()->forClass(ExportJob::class)->limit(100)->get();
```

Jobs declare their own tags:

```php
use AlveBy\QueueManager\Contracts\Taggable;

class ExportJob implements ShouldQueue, Taggable
{
    public function __construct(public int $clientId) {}

    public function queueTags(): array
    {
        return ['client:'.$this->clientId, 'tenant:'.tenant()->id];
    }
}
```

`class:`, `queue:` and `connection:` tags are added automatically
(`index.auto_tags`).

Without tags the query walks the real Redis structures instead, which is always
accurate but O(n) in queue length:

```php
QueueManager::search()->queue('export')->state('delayed')->limit(50)->get();
```

## Other operations

```php
QueueManager::delete($uuid);                        // remove without flagging cancelled
QueueManager::runNow($uuid);                        // delayed -> available now
QueueManager::reschedule($uuid, now()->addHour());  // move a pending or delayed job
QueueManager::purge('export');                      // drop pending + delayed
QueueManager::counts('export');                     // ['pending' => n, 'delayed' => n, 'reserved' => n]
QueueManager::stats();                              // one row per queue, with pause state
QueueManager::queues();
```

`delete()` differs from `cancel()`: it only takes the job out of Redis and
leaves no flag, so a job that is already reserved will still run.

## Commands

```
queue-manager:stats                    counts and pause state for every queue
queue-manager:jobs                     list queued jobs (--queue, --state, --tag, --limit)
queue-manager:show {uuid}              everything known about one job (--payload)
queue-manager:cancel {uuid...}         stop jobs from running (--reason)
queue-manager:delete {uuid...}         remove jobs without flagging them
queue-manager:pause {queue}            stop consuming (--for="10 minutes", --reason)
queue-manager:resume {queue}           start consuming again (--all)
queue-manager:paused                   list paused queues
queue-manager:purge {queue}            drop every job (--state=pending --state=delayed)
queue-manager:prune                    drop stale index and cancellation entries
```

Schedule `queue-manager:prune` daily if you leave the index on.

## Events

| Event | When |
| --- | --- |
| `JobCancelled` | `cancel()` was called |
| `CancelledJobSkipped` | a worker actually dropped a cancelled job |
| `JobRemoved` | a job was deleted out of a queue |
| `QueuePaused` / `QueueResumed` | pause state changed |

## How it works

The package reads and edits the structures `Illuminate\Queue\RedisQueue`
maintains:

```
queues:{name}           LIST   payloads waiting for a worker
queues:{name}:delayed   ZSET   payload => timestamp it becomes available
queues:{name}:reserved  ZSET   payload => timestamp it may be retried
queues:{name}:notify    LIST   one token per pending job, so workers can BLPOP
```

Two details matter and are easy to get wrong by hand:

**Match on uuid, not on score.** Scores in the delayed and reserved sets are
timestamps, and jobs routinely share one. The uuid lives in the payload and is
stable across the whole lifecycle, including the cjson re-encode Redis performs
when a job is reserved.

**Keep the notify list in sync.** Every push adds a token to `:notify` and
every pop removes one. An `LREM` on the queue list without a matching `LPOP` on
the notify list leaves the two permanently out of step and wakes workers for
jobs that are not there. Every removal here does both in one Lua script.

Its own keys, all under the configured prefix:

```
lqm:paused          HASH  "{connection}:{queue}" => pause record
lqm:cancelled:{u}   STR   cancellation flag, TTL'd
lqm:cancelled       ZSET  uuid => cancelled at, for listing and pruning
lqm:job:{uuid}      HASH  index record, TTL'd
lqm:tag:{tag}       SET   uuids carrying that tag
lqm:jobs            ZSET  uuid => queued at
lqm:queues          SET   queue names seen
```

A job leaves the tag sets and `lqm:jobs` as soon as it reaches a terminal
state, so those stay proportional to in-flight work rather than to total
throughput.

## Limitations

- **Redis only.** The API is behind contracts (`JobRepository`, `PauseStore`,
  `CancellationStore`, `JobIndex`) so other drivers can be added, but only
  Redis is implemented. Cancellation itself is driver-agnostic — it hangs off
  the `JobProcessing` event — but finding and deleting jobs is not.
- **A running job cannot be killed**, only asked to stop. Use `Cancellable`.
- **Deleting a reserved job** does not stop a worker that is executing it. It
  only removes the reservation, meaning nothing will retry that job if the
  worker dies.
- **Lookups without a queue hint are O(n)** across every queue. Pass a queue,
  or use tags.
- **The index only knows jobs queued since installation**, and only until they
  finish. Scanning is always the source of truth.
- **Encrypted payloads** (`ShouldBeEncrypted`) hide the batch id, so batch
  progress is not recorded when such a job is cancelled.

## Testing

Feature tests need a Redis they are allowed to `FLUSHDB`:

```bash
docker run -d --rm --name lqm-test-redis -p 63799:6379 redis:7-alpine
```

```bash
vendor/bin/phpunit
```

Tests skip themselves when nothing answers on that port — a run reporting 40-odd
skips means Redis was unreachable, not that everything passed. Override with
`REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` and `REDIS_QUEUE_DB`, and switch
clients with `REDIS_CLIENT=phpredis`. CI runs both clients, because they
disagree about key prefixing, variadic arguments and `SCAN`.

```bash
composer lint
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues go through
[SECURITY.md](SECURITY.md), not the issue tracker.

## License

MIT. See [LICENSE.md](LICENSE.md).
