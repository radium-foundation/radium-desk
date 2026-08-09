<?php

namespace App\Notifications;

use App\Models\Reminder;
use App\Models\Todo;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TodoReminderDueNotification extends Notification
{
    use Queueable;

    public const TYPE = 'todo.reminder';

    public function __construct(
        private readonly Todo $todo,
        private readonly Reminder $reminder,
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
        return [
            'title' => 'To-Do Reminder',
            'message' => $this->todo->title,
            'url' => route('todos.show', $this->todo),
            'type' => self::TYPE,
            'todo_id' => $this->todo->id,
            'reminder_id' => $this->reminder->id,
            'idempotency_key' => $this->reminder->idempotency_key,
        ];
    }
}
