<?php

declare(strict_types=1);

namespace AlveBy\QueueManager\Exceptions;

/**
 * Thrown by Cancellable::abortIfCancelled(). The job has already been deleted
 * by the time this is thrown, so it will not be retried.
 */
class JobWasCancelled extends QueueManagerException {}
