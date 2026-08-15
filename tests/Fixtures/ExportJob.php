<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Tests\Fixtures;

use AlveBy\QueueManager\Concerns\Cancellable;
use AlveBy\QueueManager\Contracts\Taggable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExportJob implements ShouldQueue, Taggable
{
    use Cancellable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var array<int, string> */
    public static array $handled = [];

    public function __construct(public readonly string $clientId = '1') {}

    public function handle(): void
    {
        static::$handled[] = $this->clientId;
    }

    public function queueTags(): array
    {
        return ['client:'.$this->clientId];
    }
}
