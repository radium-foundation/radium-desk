<?php

namespace App\Events\Dashboard;

use App\Data\RealtimeNotification;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RealtimeNotificationDelivered implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

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
