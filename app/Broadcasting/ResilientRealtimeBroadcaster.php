<?php

namespace App\Broadcasting;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Enqueue ShouldBroadcast events without failing the surrounding HTTP/webhook transaction.
 */
class ResilientRealtimeBroadcaster
{
    public function dispatch(object $event): void
    {
        try {
            broadcast($event);
        } catch (Throwable $exception) {
            Log::warning('realtime.broadcast.dispatch_failed', [
                'event' => $event::class,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
