<?php

namespace App\Events\Dashboard;

use App\Broadcasting\Concerns\UsesQueuedCriticalRealtimeBroadcast;
use App\Data\OperatorAlert;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OperatorAlertRaised implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;
    use UsesQueuedCriticalRealtimeBroadcast;

    public function __construct(
        public User $recipient,
        public OperatorAlert $alert,
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
        return 'OperatorAlertRaised';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->alert->toBroadcastPayload();
    }
}
