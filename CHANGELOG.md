# Changelog

All notable changes to `alveby/laravel-queue-manager` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-08-15

### Added

- Cancel a job by uuid, wherever it is. Pending and delayed jobs are removed
  from Redis outright; anything already reserved is flagged so the worker drops
  it before `handle()` runs.
- `Cancellable` trait, so a long-running job can poll `$this->cancelled()` and
  stop cooperatively.
- `SkipIfCancelled` job middleware, for the framework's own skip path where
  batch and chain bookkeeping is handled by `CallQueuedHandler`.
- Batch progress is recorded when a batched job is cancelled, so the batch can
  still finish.
- Pause and resume queues, with an optional expiry and a recorded reason.
  Workers keep running and stop consuming; nothing is moved or lost. Kept in
  sync with the framework's own `queue:pause` when that exists.
- Horizon support: the pausable queue extends Horizon's `RedisQueue` when
  Horizon is installed.
- Delete, reschedule and promote-to-ready for individual jobs, and purge for
  whole queues. Every removal keeps the `:notify` list in step with the queue
  list.
- Optional job index, so jobs can be found by tag (`client:123`) as well as by
  uuid. Jobs declare tags through the `Taggable` contract.
- Fluent `QueueManager::search()` builder over both the index and the raw Redis
  structures.
- Ten Artisan commands under the `queue-manager:` namespace.
- Events: `JobCancelled`, `CancelledJobSkipped`, `JobRemoved`, `QueuePaused`,
  `QueueResumed`.

[1.0.0]: https://github.com/AlveBy/laravel-queue-manager/releases/tag/v1.0.0
