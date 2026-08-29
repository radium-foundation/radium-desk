<?php

namespace App\Notifications;

use App\Models\Todo;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TodoAssignedNotification extends Notification
{
    use Queueable;

    public const TYPE = 'todo.assigned';

    public function __construct(
        private readonly Todo $todo,
        private readonly User $actor,
        private readonly string $trigger,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $actorName = $this->actor->firstName();
        $isSelf = (int) $this->actor->id === (int) $notifiable->id;

        $message = match (true) {
            $this->trigger === 'created' && $isSelf => "You created: {$this->todo->title}",
            default => "{$this->todo->title} was assigned to you by {$actorName}.",
        };

        return [
            'title' => $this->trigger === 'created' ? 'To-Do Created' : 'To-Do Assigned',
            'message' => $message,
            'url' => route('todos.show', $this->todo),
            'type' => self::TYPE,
            'todo_id' => $this->todo->id,
            'trigger' => $this->trigger,
        ];
    }
}
