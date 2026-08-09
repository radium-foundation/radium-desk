<?php

namespace App\Services\Reminders;

use Illuminate\Support\Carbon;

class TodoReminderIdempotencyKeyGenerator
{
    public function generate(int $todoId, Carbon $remindAt): string
    {
        $normalized = $remindAt->copy()->timezone((string) config('app.timezone'));

        return sprintf(
            'todo-reminder.%d.%s',
            $todoId,
            $normalized->format('Y-m-d\TH:i:sP'),
        );
    }
}
