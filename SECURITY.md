# Security Policy

## Supported versions

The latest minor release receives security fixes.

## Reporting a vulnerability

Please do not open a public issue. Report vulnerabilities privately through
GitHub's [security advisory form](https://github.com/AlveBy/laravel-queue-manager/security/advisories/new),
or by email to info@alve.by.

Include what an attacker can do, the versions affected, and a reproduction if
you have one. You will get an acknowledgement within a few days.

## Scope note

This package can delete queued jobs and stop queues from being consumed. Any
route, command or listener you wire to `QueueManager::cancel()`,
`::delete()`, `::purge()` or `::pause()` is destructive by design — authorise
it as carefully as you would a delete endpoint. Job uuids are guessable only
if you leak them, but they are the sole thing `cancel()` requires.
