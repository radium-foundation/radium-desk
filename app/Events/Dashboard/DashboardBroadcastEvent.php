<?php

namespace App\Events\Dashboard;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class DashboardBroadcastEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public User $recipient,
        public Incident $incident,
        public string $rowHtml,
        public bool $removeFromList = false,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('dashboard.'.$this->recipient->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'incident_id' => $this->incident->id,
            'html' => $this->rowHtml,
            'remove_from_list' => $this->removeFromList,
        ];
    }
}
