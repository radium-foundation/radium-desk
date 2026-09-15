<?php

namespace App\Events\Dashboard;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Hardware workspace fan-out. HTML is not included — clients refetch
 * GET /dashboard/live/hardware?ids[]= like hybrid incident rows.
 *
 * @phpstan-type HardwareFulfilmentPayload array{
 *     fulfilment_id: int,
 *     source_id: string,
 *     incident_id: int|null,
 *     queue: string,
 *     status: string,
 *     next_action: string,
 *     updated_at: string
 * }
 */
class HardwareFulfilmentsUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  list<HardwareFulfilmentPayload>  $fulfilments
     */
    public function __construct(
        public User $recipient,
        public array $fulfilments,
    ) {}

    public function broadcastAs(): string
    {
        return 'HardwareFulfilmentsUpdated';
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
     * @return array{
     *     fulfilment_ids: list<int>,
     *     fulfilments: list<HardwareFulfilmentPayload>,
     *     updated_at: string|null
     * }
     */
    public function broadcastWith(): array
    {
        $ids = [];
        foreach ($this->fulfilments as $row) {
            $ids[] = (int) $row['fulfilment_id'];
        }

        return [
            'fulfilment_ids' => $ids,
            'fulfilments' => array_values($this->fulfilments),
            'updated_at' => $this->fulfilments[0]['updated_at'] ?? null,
        ];
    }
}
