<?php

namespace App\Events\Dashboard;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public User $recipient,
        public string $notificationId,
        public string $title,
        public string $message,
        public string $url,
        public int $unreadCount,
        public string $bellHtml,
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
        return 'NotificationCreated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notificationId,
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'unread_count' => $this->unreadCount,
            'bell_html' => $this->bellHtml,
        ];
    }
}
