<?php

namespace App\Events\Dashboard;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IncomingCallReceived implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array{
     *     call_id: string,
     *     customer_name: ?string,
     *     mobile_number: ?string,
     *     call_status: string,
     *     assigned_operator: ?string,
     *     received_at: string,
     *     incident_id: ?int,
     *     action_url: ?string
     * }  $call
     */
    public function __construct(
        public User $recipient,
        public array $call,
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
        return 'IncomingCallReceived';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'call' => $this->call,
        ];
    }
}
