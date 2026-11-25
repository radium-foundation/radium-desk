<?php

namespace App\Broadcasting\Concerns;

use App\Infrastructure\Queue\QueueRouting;

/**
 * Latency-sensitive realtime UI (incoming calls, operator alerts).
 */
trait UsesQueuedCriticalRealtimeBroadcast
{
    public int $tries = 3;

    public function broadcastQueue(): string
    {
        return QueueRouting::critical();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [1, 2, 5];
    }
}
