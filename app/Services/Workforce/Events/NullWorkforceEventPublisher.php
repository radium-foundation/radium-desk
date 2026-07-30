<?php

namespace App\Services\Workforce\Events;

use App\Contracts\Workforce\WorkforceEventPublisher;
use App\Data\Workforce\WorkforceEvent;

/**
 * Default Milestone 3 publisher — intentional no-op extension point.
 */
class NullWorkforceEventPublisher implements WorkforceEventPublisher
{
    public function publish(WorkforceEvent $event): void
    {
        // Intentionally empty. Attendance must not depend on event delivery.
    }
}
