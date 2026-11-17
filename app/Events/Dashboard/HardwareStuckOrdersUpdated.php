<?php

namespace App\Events\Dashboard;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HardwareStuckOrdersUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array{needs_action_total: int, breakdown: list<array{filter: string, label: string, count: int, tone: string}>}  $summary
     */
    public function __construct(
        public User $recipient,
        public array $summary,
        public string $html,
        public int $capturedAt,
        public int $version,
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

    public function broadcastAs(): string
    {
        return 'HardwareStuckOrdersUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'summary' => $this->summary,
            'html' => $this->html,
            'captured_at' => $this->capturedAt,
            'version' => $this->version,
        ];
    }
}
