<?php

namespace App\Services\Todos;

use App\Enums\ReminderStatus;
use App\Enums\TodoPriority;
use App\Enums\TodoStatus;
use App\Models\Reminder;
use App\Models\Todo;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Reminders\TodoReminderIdempotencyKeyGenerator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TodoService
{
    public const EVENT_CREATED = 'todo.created';

    public const EVENT_UPDATED = 'todo.updated';

    public const EVENT_ASSIGNED = 'todo.assigned';

    public const EVENT_COMPLETED = 'todo.completed';

    public const EVENT_REOPENED = 'todo.reopened';

    public const EVENT_CANCELLED = 'todo.cancelled';

    public const EVENT_DELETED = 'todo.deleted';

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly TodoReminderIdempotencyKeyGenerator $reminderIdempotencyKeyGenerator,
    ) {}

    /**
     * @param  array{
     *     title: string,
     *     description?: ?string,
     *     priority?: string|TodoPriority|null,
     *     assigned_to?: int|null,
     *     due_at?: Carbon|string|null,
     *     remind_at?: Carbon|string|null
     * }  $data
     */
    public function create(User $actor, array $data): Todo
    {
        Gate::forUser($actor)->authorize('create', Todo::class);

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => 'A title is required.',
            ]);
        }

        $assigneeId = (int) ($data['assigned_to'] ?? $actor->id);
        if ($assigneeId !== (int) $actor->id && ! $actor->can('todos.assign')) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $this->assertAssigneeExists($assigneeId);

        $priority = $this->resolvePriority($data['priority'] ?? null);
        $dueAt = $this->parseOptionalDateTime($data['due_at'] ?? null, 'due_at');
        $remindAt = $this->parseOptionalDateTime($data['remind_at'] ?? null, 'remind_at');

        return DB::transaction(function () use ($actor, $data, $title, $assigneeId, $priority, $dueAt, $remindAt): Todo {
            $todo = Todo::query()->create([
                'created_by' => $actor->id,
                'assigned_to' => $assigneeId,
                'title' => $title,
                'description' => $this->nullableString($data['description'] ?? null),
                'priority' => $priority,
                'status' => TodoStatus::Open,
                'due_at' => $dueAt,
                'completed_at' => null,
            ]);

            $this->syncReminder($todo, $remindAt);

            $todo = $todo->fresh(['creator', 'assignee', 'reminders']) ?? $todo;

            $this->auditLogService->log(
                userId: $actor->id,
                event: self::EVENT_CREATED,
                auditable: $todo,
                newValues: $this->todoAuditValues($todo, $remindAt),
            );

            return $todo;
        });
    }

    /**
     * @param  array{
     *     title?: string,
     *     description?: ?string,
     *     priority?: string|TodoPriority|null,
     *     assigned_to?: int|null,
     *     due_at?: Carbon|string|null,
     *     remind_at?: Carbon|string|null
     * }  $data
     */
    public function update(User $actor, Todo $todo, array $data): Todo
    {
        Gate::forUser($actor)->authorize('update', $todo);

        return DB::transaction(function () use ($actor, $todo, $data): Todo {
            $locked = $this->lockTodo($todo);
            $this->assertNotCancelled($locked);

            $oldValues = $this->todoAuditValues($locked);

            if (array_key_exists('assigned_to', $data)
                && (int) $data['assigned_to'] !== (int) $locked->assigned_to
            ) {
                Gate::forUser($actor)->authorize('assign', $locked);
                $this->assertAssigneeExists((int) $data['assigned_to']);
                $locked->assigned_to = (int) $data['assigned_to'];
            }

            if (array_key_exists('title', $data)) {
                $title = trim((string) $data['title']);
                if ($title === '') {
                    throw ValidationException::withMessages([
                        'title' => 'A title is required.',
                    ]);
                }
                $locked->title = $title;
            }

            if (array_key_exists('description', $data)) {
                $locked->description = $this->nullableString($data['description']);
            }

            if (array_key_exists('priority', $data)) {
                $locked->priority = $this->resolvePriority($data['priority']);
            }

            if (array_key_exists('due_at', $data)) {
                $locked->due_at = $this->parseOptionalDateTime($data['due_at'], 'due_at');
            }

            $locked->save();

            $remindAt = array_key_exists('remind_at', $data)
                ? $this->parseOptionalDateTime($data['remind_at'], 'remind_at')
                : $this->currentPendingRemindAt($locked);

            if (array_key_exists('remind_at', $data) || array_key_exists('assigned_to', $data)) {
                $this->syncReminder($locked, $remindAt);
            }

            $locked = $locked->fresh(['creator', 'assignee', 'reminders']) ?? $locked;

            $this->auditLogService->log(
                userId: $actor->id,
                event: self::EVENT_UPDATED,
                auditable: $locked,
                oldValues: $oldValues,
                newValues: $this->todoAuditValues($locked, $remindAt),
            );

            return $locked;
        });
    }

    public function assign(User $actor, Todo $todo, User $assignee): Todo
    {
        Gate::forUser($actor)->authorize('assign', $todo);

        if (! $assignee->is_active) {
            throw ValidationException::withMessages([
                'assigned_to' => 'The selected assignee is inactive.',
            ]);
        }

        return DB::transaction(function () use ($actor, $todo, $assignee): Todo {
            $locked = $this->lockTodo($todo);
            $this->assertNotCancelled($locked);

            $previousAssigneeId = (int) $locked->assigned_to;
            $locked->assigned_to = $assignee->id;
            $locked->save();

            $this->syncReminder($locked, $this->currentPendingRemindAt($locked));

            $locked = $locked->fresh(['creator', 'assignee', 'reminders']) ?? $locked;

            $this->auditLogService->log(
                userId: $actor->id,
                event: self::EVENT_ASSIGNED,
                auditable: $locked,
                oldValues: ['assigned_to' => $previousAssigneeId],
                newValues: ['assigned_to' => (int) $locked->assigned_to],
            );

            return $locked;
        });
    }

    public function complete(User $actor, Todo $todo): Todo
    {
        Gate::forUser($actor)->authorize('complete', $todo);

        return DB::transaction(function () use ($actor, $todo): Todo {
            $locked = $this->lockTodo($todo);
            $this->assertNotCancelled($locked);

            if ($locked->status === TodoStatus::Completed) {
                return $locked->fresh(['creator', 'assignee', 'reminders']) ?? $locked;
            }

            if ($locked->status !== TodoStatus::Open) {
                throw ValidationException::withMessages([
                    'status' => 'Only open to-dos can be completed.',
                ]);
            }

            $locked->fill([
                'status' => TodoStatus::Completed,
                'completed_at' => now(),
            ])->save();

            $this->cancelPendingReminders($locked);

            $locked = $locked->fresh(['creator', 'assignee', 'reminders']) ?? $locked;

            $this->auditLogService->log(
                userId: $actor->id,
                event: self::EVENT_COMPLETED,
                auditable: $locked,
                newValues: [
                    'status' => $locked->status->value,
                    'completed_at' => $locked->completed_at?->toIso8601String(),
                ],
            );

            return $locked;
        });
    }

    public function reopen(User $actor, Todo $todo): Todo
    {
        Gate::forUser($actor)->authorize('update', $todo);

        return DB::transaction(function () use ($actor, $todo): Todo {
            $locked = $this->lockTodo($todo);
            $this->assertNotCancelled($locked);

            if ($locked->status === TodoStatus::Open) {
                return $locked->fresh(['creator', 'assignee', 'reminders']) ?? $locked;
            }

            if ($locked->status !== TodoStatus::Completed) {
                throw ValidationException::withMessages([
                    'status' => 'Only completed to-dos can be reopened.',
                ]);
            }

            $locked->fill([
                'status' => TodoStatus::Open,
                'completed_at' => null,
            ])->save();

            $locked = $locked->fresh(['creator', 'assignee', 'reminders']) ?? $locked;

            $this->auditLogService->log(
                userId: $actor->id,
                event: self::EVENT_REOPENED,
                auditable: $locked,
                newValues: [
                    'status' => $locked->status->value,
                    'completed_at' => null,
                ],
            );

            return $locked;
        });
    }

    public function cancel(User $actor, Todo $todo): Todo
    {
        Gate::forUser($actor)->authorize('cancel', $todo);

        return DB::transaction(function () use ($actor, $todo): Todo {
            $locked = $this->lockTodo($todo);

            if ($locked->status === TodoStatus::Cancelled) {
                return $locked->fresh(['creator', 'assignee', 'reminders']) ?? $locked;
            }

            $locked->fill([
                'status' => TodoStatus::Cancelled,
                'completed_at' => null,
            ])->save();

            $this->cancelPendingReminders($locked);

            $locked = $locked->fresh(['creator', 'assignee', 'reminders']) ?? $locked;

            $this->auditLogService->log(
                userId: $actor->id,
                event: self::EVENT_CANCELLED,
                auditable: $locked,
                newValues: [
                    'status' => $locked->status->value,
                ],
            );

            return $locked;
        });
    }

    public function delete(User $actor, Todo $todo): void
    {
        Gate::forUser($actor)->authorize('delete', $todo);

        DB::transaction(function () use ($actor, $todo): void {
            $locked = $this->lockTodo($todo);
            $auditValues = $this->todoAuditValues($locked);

            $this->cancelPendingReminders($locked);

            $this->auditLogService->log(
                userId: $actor->id,
                event: self::EVENT_DELETED,
                auditable: $locked,
                oldValues: $auditValues,
            );

            $locked->delete();
        });
    }

    /**
     * Schedule or clear a reminder for an open to-do. Does not dispatch.
     */
    public function scheduleReminder(User $actor, Todo $todo, ?Carbon $remindAt): Todo
    {
        Gate::forUser($actor)->authorize('update', $todo);

        return DB::transaction(function () use ($todo, $remindAt): Todo {
            $locked = $this->lockTodo($todo);

            if ($locked->status === TodoStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'remind_at' => 'Cancelled to-dos cannot receive reminders.',
                ]);
            }

            if ($locked->status === TodoStatus::Completed) {
                throw ValidationException::withMessages([
                    'remind_at' => 'Completed to-dos cannot receive new reminders.',
                ]);
            }

            $this->syncReminder($locked, $remindAt);

            return $locked->fresh(['creator', 'assignee', 'reminders']) ?? $locked;
        });
    }

    private function syncReminder(Todo $todo, ?Carbon $remindAt): void
    {
        if ($todo->status === TodoStatus::Cancelled) {
            if ($remindAt !== null) {
                throw ValidationException::withMessages([
                    'remind_at' => 'Cancelled to-dos cannot receive reminders.',
                ]);
            }

            $this->cancelPendingReminders($todo);

            return;
        }

        if ($todo->status === TodoStatus::Completed || $remindAt === null) {
            $this->cancelPendingReminders($todo);

            return;
        }

        $key = $this->reminderIdempotencyKeyGenerator->generate((int) $todo->id, $remindAt);

        Reminder::query()
            ->where('remindable_type', $todo->getMorphClass())
            ->where('remindable_id', $todo->id)
            ->where('status', ReminderStatus::Pending->value)
            ->where('idempotency_key', '!=', $key)
            ->update(['status' => ReminderStatus::Cancelled->value]);

        // In-flight claims must not be rewritten to a new schedule.
        Reminder::query()
            ->where('remindable_type', $todo->getMorphClass())
            ->where('remindable_id', $todo->id)
            ->where('status', ReminderStatus::Processing->value)
            ->where('idempotency_key', '!=', $key)
            ->update(['status' => ReminderStatus::Cancelled->value]);

        $existing = Reminder::query()
            ->where('idempotency_key', $key)
            ->first();

        if ($existing !== null) {
            if (in_array($existing->status, [ReminderStatus::Dispatched, ReminderStatus::Processing], true)) {
                return;
            }

            $existing->fill([
                'remindable_type' => $todo->getMorphClass(),
                'remindable_id' => $todo->id,
                'user_id' => $todo->assigned_to,
                'remind_at' => $remindAt,
                'status' => ReminderStatus::Pending,
                'dispatched_at' => null,
                'notification_id' => null,
                'metadata' => array_merge($existing->metadata ?? [], [
                    'source' => 'todo',
                ]),
            ])->save();

            return;
        }

        Reminder::query()->create([
            'remindable_type' => $todo->getMorphClass(),
            'remindable_id' => $todo->id,
            'user_id' => $todo->assigned_to,
            'remind_at' => $remindAt,
            'status' => ReminderStatus::Pending,
            'idempotency_key' => $key,
            'metadata' => ['source' => 'todo'],
        ]);
    }

    private function cancelPendingReminders(Todo $todo): void
    {
        Reminder::query()
            ->where('remindable_type', $todo->getMorphClass())
            ->where('remindable_id', $todo->id)
            ->whereIn('status', [
                ReminderStatus::Pending->value,
                ReminderStatus::Processing->value,
            ])
            ->update(['status' => ReminderStatus::Cancelled->value]);
    }

    private function currentPendingRemindAt(Todo $todo): ?Carbon
    {
        $pending = Reminder::query()
            ->where('remindable_type', $todo->getMorphClass())
            ->where('remindable_id', $todo->id)
            ->where('status', ReminderStatus::Pending->value)
            ->orderBy('remind_at')
            ->first();

        return $pending?->remind_at;
    }

    private function lockTodo(Todo $todo): Todo
    {
        $locked = Todo::query()->whereKey($todo->id)->lockForUpdate()->first();

        if ($locked === null) {
            throw ValidationException::withMessages([
                'todo' => 'The to-do could not be found.',
            ]);
        }

        return $locked;
    }

    private function assertNotCancelled(Todo $todo): void
    {
        if ($todo->status === TodoStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => 'Cancelled to-dos cannot be modified.',
            ]);
        }
    }

    private function assertAssigneeExists(int $assigneeId): void
    {
        $exists = User::query()
            ->whereKey($assigneeId)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'assigned_to' => 'The selected assignee is invalid.',
            ]);
        }
    }

    private function resolvePriority(TodoPriority|string|null $priority): TodoPriority
    {
        if ($priority instanceof TodoPriority) {
            return $priority;
        }

        if ($priority === null || $priority === '') {
            return TodoPriority::Normal;
        }

        $resolved = TodoPriority::tryFrom((string) $priority);

        if ($resolved === null) {
            throw ValidationException::withMessages([
                'priority' => 'The selected priority is invalid.',
            ]);
        }

        return $resolved;
    }

    private function parseOptionalDateTime(Carbon|string|null $value, string $field): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->copy()->timezone((string) config('app.timezone'));
        }

        try {
            return Carbon::parse((string) $value, (string) config('app.timezone'));
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $field => 'The selected date/time is invalid.',
            ]);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    private function todoAuditValues(Todo $todo, ?Carbon $remindAt = null): array
    {
        $pendingRemindAt = $remindAt ?? $this->currentPendingRemindAt($todo);

        return [
            'title' => $todo->title,
            'description' => $todo->description,
            'priority' => $todo->priority instanceof TodoPriority
                ? $todo->priority->value
                : $todo->priority,
            'status' => $todo->status instanceof TodoStatus
                ? $todo->status->value
                : $todo->status,
            'created_by' => (int) $todo->created_by,
            'assigned_to' => (int) $todo->assigned_to,
            'due_at' => $todo->due_at?->toIso8601String(),
            'completed_at' => $todo->completed_at?->toIso8601String(),
            'remind_at' => $pendingRemindAt?->toIso8601String(),
        ];
    }
}
