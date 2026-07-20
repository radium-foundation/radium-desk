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

    /**
     * @param  array<string, string>  $listActions
     */
    public function __construct(
        public User $recipient,
        public Incident $incident,
        public string $incidentQueue,
        public array $listActions,
        public ?string $rowHtml = null,
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
        $payload = [
            'incident_id' => $this->incident->id,
            'queue' => $this->incidentQueue,
            'list_actions' => $this->listActions,
        ];

        if ($this->rowHtml !== null) {
            $payload['html'] = $this->rowHtml;
        }

        return $payload;
    }
}
