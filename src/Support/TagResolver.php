<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Support;

use AlveBy\QueueManager\Contracts\Taggable;

/**
 * Decides how a job will be findable later.
 *
 * Automatic tags come from the config; a job adds its own by implementing
 * Taggable. Tags become Redis set keys, so they are trimmed and length
 * capped, but otherwise kept verbatim — "client:123" is a tag, not a slug.
 */
final class TagResolver
{
    private const MAX_LENGTH = 200;

    /**
     * @param  array<int, string>  $autoTags
     */
    public function __construct(private readonly array $autoTags = ['class', 'queue', 'connection']) {}

    /**
     * @return array<int, string>
     */
    public function for(mixed $job, string $connection, string $queue, string $name): array
    {
        $tags = [];

        if (in_array('class', $this->autoTags, true)) {
            $tags[] = 'class:'.$name;
        }

        if (in_array('queue', $this->autoTags, true)) {
            $tags[] = 'queue:'.$queue;
        }

        if (in_array('connection', $this->autoTags, true)) {
            $tags[] = 'connection:'.$connection;
        }

        if ($job instanceof Taggable) {
            foreach ($job->queueTags() as $tag) {
                $tags[] = (string) $tag;
            }
        }

        return $this->normalize($tags);
    }

    /**
     * @param  array<int, string>  $tags
     * @return array<int, string>
     */
    public function normalize(array $tags): array
    {
        $normalized = [];

        foreach ($tags as $tag) {
            $tag = trim((string) $tag);

            if ($tag === '') {
                continue;
            }

            $normalized[] = mb_substr($tag, 0, self::MAX_LENGTH);
        }

        return array_values(array_unique($normalized));
    }

    public static function forClass(string $class): string
    {
        return 'class:'.ltrim($class, '\\');
    }

    public static function forQueue(string $queue): string
    {
        return 'queue:'.$queue;
    }

    public static function forConnection(string $connection): string
    {
        return 'connection:'.$connection;
    }
}
