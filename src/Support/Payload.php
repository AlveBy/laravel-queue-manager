<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Support;

use Illuminate\Queue\CallQueuedHandler;

/**
 * Read-only helpers for the JSON payload Laravel stores in Redis.
 *
 * Nothing here unserializes the command: a payload can hold arbitrary user
 * objects, and management code has no business waking them up.
 */
final class Payload
{
    /**
     * The substrings a payload carrying this uuid could contain.
     *
     * Both PHP's json_encode and Redis' cjson (which re-encodes the payload
     * when a job is reserved) emit `"uuid":"..."` without spaces, and neither
     * reorders it away, so this is a safe pre-filter before a full decode.
     *
     * They disagree on one thing: PHP escapes forward slashes, cjson does
     * not. Standard uuids contain neither, but a custom uuid generator might,
     * so both spellings are checked.
     *
     * @return array<int, string>
     */
    public static function needles(string $uuid): array
    {
        return array_values(array_unique([
            '"uuid":'.json_encode($uuid, JSON_UNESCAPED_UNICODE),
            '"uuid":'.json_encode($uuid, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]));
    }

    /**
     * Cheap check first, authoritative decode second.
     */
    public static function matches(string $raw, string $uuid): bool
    {
        foreach (self::needles($uuid) as $needle) {
            if (str_contains($raw, $needle)) {
                return self::uuid(self::decode($raw) ?? []) === $uuid;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decode(string $raw): ?array
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function uuid(array $payload): ?string
    {
        $uuid = $payload['uuid'] ?? null;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function id(array $payload): ?string
    {
        $id = $payload['id'] ?? null;

        return is_scalar($id) ? (string) $id : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function displayName(array $payload): string
    {
        foreach (['displayName', 'data.commandName', 'job'] as $path) {
            $value = data_get($payload, $path);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function attempts(array $payload): int
    {
        return (int) ($payload['attempts'] ?? 0);
    }

    /**
     * Whether this job will be executed through CallQueuedHandler, which is
     * what gives us batch and chain bookkeeping. Raw string jobs pushed with
     * Queue::push('Class@method') will not.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function goesThroughCommandBus(array $payload): bool
    {
        $job = $payload['job'] ?? null;

        return is_string($job) && str_starts_with(ltrim($job, '\\'), CallQueuedHandler::class);
    }

    /**
     * Pull the batch id out of the serialized command without unserializing it.
     *
     * Illuminate\Bus\Batchable declares `public ?string $batchId`, so it lands
     * in the serialized string as s:7:"batchId";s:36:"<uuid>";. Returns null
     * for non-batched jobs and for encrypted payloads.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function batchId(array $payload): ?string
    {
        $command = $payload['data']['command'] ?? null;

        if (! is_string($command)) {
            return null;
        }

        if (! preg_match('/s:7:"batchId";s:(\d+):"/', $command, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $length = (int) $matches[1][0];
        $start = $matches[0][1] + strlen($matches[0][0]);

        $value = substr($command, $start, $length);

        return $value === '' ? null : $value;
    }
}
