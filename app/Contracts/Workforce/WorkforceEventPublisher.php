<?php

namespace App\Contracts\Workforce;

use App\Data\Workforce\WorkforceEvent;

/**
 * Additive WorkforceEvent publisher port.
 * Default binding is NullWorkforceEventPublisher (no-op).
 * Persistence/outbox publishers are optional and must be disabled by default.
 */
interface WorkforceEventPublisher
{
    public function publish(WorkforceEvent $event): void;
}
