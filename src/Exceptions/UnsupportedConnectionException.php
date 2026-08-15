<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Exceptions;

class UnsupportedConnectionException extends QueueManagerException
{
    /**
     * @param  array<int, string>  $available
     */
    public static function for(string $name, array $available): self
    {
        $list = $available === [] ? 'none' : implode(', ', $available);

        return new self(
            "Queue connection [{$name}] is not managed by laravel-queue-manager. ".
            "Managed connections: {$list}. Only connections using the \"redis\" driver are supported, ".
            'and they must not be excluded by the queue-manager.connections config value.'
        );
    }

    public static function none(): self
    {
        return new self(
            'laravel-queue-manager found no queue connection using the "redis" driver. '.
            'Check config/queue.php, or narrow queue-manager.connections to the ones you want managed.'
        );
    }
}
