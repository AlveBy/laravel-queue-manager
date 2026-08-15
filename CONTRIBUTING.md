# Contributing

Thanks for taking the time. Bug reports and pull requests are both welcome.

## Reporting a bug

Include the Laravel version, the PHP version, whether you are on phpredis or
predis, and whether Horizon is installed. Those four decide most of the
behaviour in this package.

If the bug is about a job going missing or being run twice, the output of
`php artisan queue-manager:show {uuid}` and `queue-manager:stats` is worth far
more than a description.

## Running the tests

Feature tests talk to a real Redis and call `FLUSHDB`, so give them a
throwaway server rather than your development one:

```bash
docker run -d --rm --name lqm-test-redis -p 63799:6379 redis:7-alpine
composer install
composer test
```

Tests skip themselves when nothing answers on that port, so a green run that
reports 40-odd skips means Redis was not reachable, not that everything passed.

Override the target with `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` and
`REDIS_QUEUE_DB`. `REDIS_CLIENT=phpredis` switches clients if you have the
extension installed — CI runs both, because they disagree about key prefixing,
variadic arguments and `SCAN`.

## Code style

```bash
composer lint
```

Laravel Pint with `declare(strict_types=1)` on. CI fails on a style diff.

## Pull requests

- One concern per pull request.
- Add a test. The queue structures are easy to break in ways that only show up
  under load, so a change without a test is hard to accept.
- Update `CHANGELOG.md` under `## [Unreleased]`.
- If you change how a job is located or removed, say in the description why
  the `:notify` list stays in step with the queue list. That invariant is the
  one most likely to be broken by accident.

## Things that are deliberate

Before proposing a change, these are choices rather than oversights:

- **Cancelling a job cancels the rest of its chain.** The `JobProcessing`
  listener deletes the job before `CallQueuedHandler` runs, so the chain is
  never dispatched. Batches are credited explicitly; chains are not.
- **A reserved job is flagged, not deleted.** Pulling the reservation would
  only remove its retry safety net without stopping the worker.
- **Lookups without a queue hint are O(n).** That is what the tag index is for.
- **Redis only.** The contracts exist so another driver can be added, but
  nothing else is implemented.
