<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Contracts;

/**
 * Let a job declare how it should be findable later.
 *
 *     class ExportJob implements ShouldQueue, Taggable
 *     {
 *         public function queueTags(): array
 *         {
 *             return ['client:'.$this->clientId, 'report:'.$this->reportId];
 *         }
 *     }
 *
 * Then: QueueManager::search()->tag('client:123')->get()
 */
interface Taggable
{
    /**
     * @return array<int, string>
     */
    public function queueTags(): array;
}
