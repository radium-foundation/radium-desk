<?php

namespace App\Services\Workforce\Events;

use App\Contracts\Workforce\WorkforceEventPublisher;
use App\Data\Workforce\WorkforceEvent;
use Throwable;

/**
 * Isolates attendance / leave writes from publisher failures.
 * Always wraps the configured inner publisher (Null by default).
 */
class SafeWorkforceEventPublisher implements WorkforceEventPublisher
{
    public function __construct(
        private readonly WorkforceEventPublisher $inner,
    ) {}

    public function publish(WorkforceEvent $event): void
    {
        try {
            $this->inner->publish($event);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
