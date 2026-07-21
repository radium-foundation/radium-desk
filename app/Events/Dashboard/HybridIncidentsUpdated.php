<?php

namespace App\Events\Dashboard;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Lightweight Hybrid Realtime fan-out. Never includes rendered HTML.
 *
 * @phpstan-type HybridIncidentPayload array{
 *     incident_id: int,
 *     queue: string,
 *     status: string,
 *     updated_at: string
 * }
 */
abstract class HybridIncidentsUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  list<HybridIncidentPayload>  $incidents
     */
    public function __construct(
        public User $recipient,
        public array $incidents,
    ) {}

    abstract public function broadcastAs(): string;

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
     * @return array{
     *     incident_ids: list<int>,
     *     incidents: list<HybridIncidentPayload>,
     *     updated_at: string|null
     * }
     */
    public function broadcastWith(): array
    {
        $incidentIds = [];

        foreach ($this->incidents as $incident) {
            $incidentIds[] = (int) $incident['incident_id'];
        }

        return [
            'incident_ids' => $incidentIds,
            'incidents' => array_values($this->incidents),
            'updated_at' => $this->incidents[0]['updated_at'] ?? null,
        ];
    }
}
