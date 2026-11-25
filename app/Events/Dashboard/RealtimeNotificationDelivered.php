<?php

namespace App\Events\Dashboard;

use App\Broadcasting\Concerns\UsesQueuedCriticalRealtimeBroadcast;
use App\Data\RealtimeNotification;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RealtimeNotificationDelivered implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;
    use UsesQueuedCriticalRealtimeBroadcast;

    public function __construct(
        public User $recipient,
        public RealtimeNotification $notification,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('notifications.'.$this->recipient->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'RealtimeNotificationDelivered';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->notification->toBroadcastPayload();
    }
}
