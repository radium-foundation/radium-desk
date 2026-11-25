<?php

namespace App\Events\Dashboard;

use App\Broadcasting\Concerns\UsesQueuedRealtimeBroadcast;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReferenceNumbersUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;
    use UsesQueuedRealtimeBroadcast;

    /**
     * @param  list<int>  $incidentIds
     */
    public function __construct(
        public User $recipient,
        public array $incidentIds,
        public string $updatedAt,
    ) {}

    public function broadcastAs(): string
    {
        return 'ReferenceNumbersUpdated';
    }

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
     * @return array{incident_ids: list<int>, updated_at: string}
     */
    public function broadcastWith(): array
    {
        return [
            'incident_ids' => array_values(array_map('intval', $this->incidentIds)),
            'updated_at' => $this->updatedAt,
        ];
    }
}
