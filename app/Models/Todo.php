<?php

namespace App\Models;

use App\Enums\ReminderStatus;
use App\Enums\TodoPriority;
use App\Enums\TodoStatus;
use Database\Factories\TodoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Todo extends Model
{
    /** @use HasFactory<TodoFactory> */
    use HasFactory;

    protected $fillable = [
        'created_by',
        'assigned_to',
        'todo_category_id',
        'title',
        'description',
        'priority',
        'status',
        'due_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => TodoPriority::class,
            'status' => TodoStatus::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TodoCategory::class, 'todo_category_id');
    }

    public function reminders(): MorphMany
    {
        return $this->morphMany(Reminder::class, 'remindable');
    }

    public function pendingReminder(): ?Reminder
    {
        return $this->reminders
            ->first(fn (Reminder $reminder): bool => $reminder->status === ReminderStatus::Pending);
    }

    public function isOverdue(): bool
    {
        return $this->status === TodoStatus::Open
            && $this->due_at !== null
            && $this->due_at->lt(now());
    }
}
