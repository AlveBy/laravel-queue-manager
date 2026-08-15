<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Console\Concerns;

use AlveBy\QueueManager\Exceptions\QueueManagerException;

trait ParsesDuration
{
    /**
     * Turn "600", "10 minutes" or "2 hours" into an absolute timestamp.
     */
    protected function parseDuration(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (is_numeric($value)) {
            return time() + (int) $value;
        }

        $timestamp = strtotime('+'.ltrim($value, '+'));

        if ($timestamp === false) {
            throw new QueueManagerException(
                "Could not understand duration [{$value}]. Use seconds (600) or a phrase (\"10 minutes\")."
            );
        }

        return $timestamp;
    }
}
