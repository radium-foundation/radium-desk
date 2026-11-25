<?php

namespace App\Broadcasting\Concerns;

use App\Infrastructure\Queue\QueueRouting;

/**
 * Routes dashboard realtime events onto named queues the production worker already drains.
 */
trait UsesQueuedRealtimeBroadcast
{
    public int $tries = 3;

    public function broadcastQueue(): string
    {
        return QueueRouting::notifications();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [1, 3, 10];
    }
}
