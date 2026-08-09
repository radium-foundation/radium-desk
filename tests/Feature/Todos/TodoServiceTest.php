<?php

namespace Tests\Feature\Todos;

use App\Enums\ReminderStatus;
use App\Enums\TodoPriority;
use App\Enums\TodoStatus;
use App\Models\AuditLog;
use App\Models\Reminder;
use App\Models\Todo;
use App\Models\User;
use App\Services\Reminders\TodoReminderIdempotencyKeyGenerator;
use App\Services\Todos\TodoService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TodoServiceTest extends TestCase
{
    use RefreshDatabase;

    private TodoService $service;

    private TodoReminderIdempotencyKeyGenerator $keyGenerator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->service = app(TodoService::class);
        $this->keyGenerator = app(TodoReminderIdempotencyKeyGenerator::class);

        Carbon::setTestNow(Carbon::parse('2026-08-09 10:00:00', 'Asia/Kolkata'));
        config(['app.timezone' => 'Asia/Kolkata']);
    }

    public function test_create_personal_todo_with_defaults(): void
    {
        $actor = $this->agent();

        $todo = $this->service->create($actor, [
            'title' => 'Call customer',
        ]);

        $this->assertSame('Call customer', $todo->title);
        $this->assertSame($actor->id, $todo->created_by);
        $this->assertSame($actor->id, $todo->assigned_to);
        $this->assertSame(TodoStatus::Open, $todo->status);
        $this->assertSame(TodoPriority::Normal, $todo->priority);
        $this->assertNull($todo->description);
        $this->assertNull($todo->due_at);
        $this->assertNull($todo->completed_at);
        $this->assertSame(0, $todo->reminders()->count());

        $this->assertDatabaseHas('audit_logs', [
            'event' => TodoService::EVENT_CREATED,
            'auditable_type' => Todo::class,
            'auditable_id' => $todo->id,
            'user_id' => $actor->id,
        ]);
    }

    public function test_create_with_due_date_and_reminder(): void
    {
        $actor = $this->agent();
        $dueAt = Carbon::parse('2026-08-10 15:00:00', 'Asia/Kolkata');
        $remindAt = Carbon::parse('2026-08-10 14:00:00', 'Asia/Kolkata');

        $todo = $this->service->create($actor, [
            'title' => 'Prepare report',
            'description' => 'Weekly ops report',
            'priority' => TodoPriority::High->value,
            'due_at' => $dueAt,
            'remind_at' => $remindAt,
        ]);

        $this->assertSame(TodoPriority::High, $todo->priority);
        $this->assertSame('Weekly ops report', $todo->description);
        $this->assertTrue($todo->due_at?->equalTo($dueAt));

        $reminder = $todo->reminders()->first();
        $this->assertNotNull($reminder);
        $this->assertSame(ReminderStatus::Pending, $reminder->status);
        $this->assertSame($actor->id, $reminder->user_id);
        $this->assertTrue($reminder->remind_at?->equalTo($remindAt));
        $this->assertSame(
            $this->keyGenerator->generate($todo->id, $remindAt),
            $reminder->idempotency_key,
        );
    }

    public function test_create_assigned_todo_requires_assign_permission(): void
    {
        $actor = $this->agent();
        $assignee = $this->agent();

        $this->expectException(AuthorizationException::class);

        $this->service->create($actor, [
            'title' => 'Assigned task',
            'assigned_to' => $assignee->id,
        ]);
    }

    public function test_admin_can_create_assigned_todo(): void
    {
        $admin = $this->admin();
        $assignee = $this->agent();

        $todo = $this->service->create($admin, [
            'title' => 'Assigned task',
            'assigned_to' => $assignee->id,
        ]);

        $this->assertSame($admin->id, $todo->created_by);
        $this->assertSame($assignee->id, $todo->assigned_to);
    }

    public function test_update_fields_and_due_date(): void
    {
        $actor = $this->agent();
        $todo = $this->service->create($actor, ['title' => 'Draft']);

        $dueAt = Carbon::parse('2026-08-11 09:00:00', 'Asia/Kolkata');

        $updated = $this->service->update($actor, $todo, [
            'title' => 'Final',
            'description' => 'Details',
            'priority' => TodoPriority::Low->value,
            'due_at' => $dueAt,
        ]);

        $this->assertSame('Final', $updated->title);
        $this->assertSame('Details', $updated->description);
        $this->assertSame(TodoPriority::Low, $updated->priority);
        $this->assertTrue($updated->due_at?->equalTo($dueAt));

        $this->assertDatabaseHas('audit_logs', [
            'event' => TodoService::EVENT_UPDATED,
            'auditable_id' => $todo->id,
        ]);
    }

    public function test_update_reminder_is_idempotent_for_same_remind_at(): void
    {
        $actor = $this->agent();
        $remindAt = Carbon::parse('2026-08-10 14:00:00', 'Asia/Kolkata');

        $todo = $this->service->create($actor, [
            'title' => 'Remind me',
            'remind_at' => $remindAt,
        ]);

        $firstKey = $todo->reminders()->firstOrFail()->idempotency_key;

        $this->service->update($actor, $todo, [
            'remind_at' => $remindAt->copy(),
        ]);

        $this->assertSame(1, Reminder::query()->where('remindable_id', $todo->id)->count());
        $this->assertSame($firstKey, $todo->reminders()->firstOrFail()->idempotency_key);
        $this->assertSame(ReminderStatus::Pending, $todo->reminders()->firstOrFail()->status);
    }

    public function test_changing_remind_at_cancels_previous_pending_and_creates_new_key(): void
    {
        $actor = $this->agent();
        $firstRemindAt = Carbon::parse('2026-08-10 14:00:00', 'Asia/Kolkata');
        $secondRemindAt = Carbon::parse('2026-08-10 16:00:00', 'Asia/Kolkata');

        $todo = $this->service->create($actor, [
            'title' => 'Reschedule reminder',
            'remind_at' => $firstRemindAt,
        ]);

        $firstKey = $this->keyGenerator->generate($todo->id, $firstRemindAt);

        $this->service->update($actor, $todo, [
            'remind_at' => $secondRemindAt,
        ]);

        $this->assertDatabaseHas('reminders', [
            'idempotency_key' => $firstKey,
            'status' => ReminderStatus::Cancelled->value,
        ]);

        $pending = Reminder::query()
            ->where('remindable_id', $todo->id)
            ->where('status', ReminderStatus::Pending->value)
            ->sole();

        $this->assertSame(
            $this->keyGenerator->generate($todo->id, $secondRemindAt),
            $pending->idempotency_key,
        );
    }

    public function test_clearing_remind_at_cancels_pending_reminder(): void
    {
        $actor = $this->agent();
        $todo = $this->service->create($actor, [
            'title' => 'Clear reminder',
            'remind_at' => Carbon::parse('2026-08-10 14:00:00', 'Asia/Kolkata'),
        ]);

        $this->service->update($actor, $todo, [
            'remind_at' => null,
        ]);

        $this->assertSame(
            0,
            Reminder::query()
                ->where('remindable_id', $todo->id)
                ->where('status', ReminderStatus::Pending->value)
                ->count(),
        );
    }

    public function test_reminder_idempotency_key_is_deterministic(): void
    {
        $remindAt = Carbon::parse('2026-08-10 14:30:00', 'Asia/Kolkata');

        $first = $this->keyGenerator->generate(42, $remindAt);
        $second = $this->keyGenerator->generate(42, $remindAt->copy());

        $this->assertSame($first, $second);
        $this->assertSame('todo-reminder.42.2026-08-10T14:30:00+05:30', $first);
    }

    public function test_assign_updates_assignee_and_pending_reminder_target(): void
    {
        $admin = $this->admin();
        $assignee = $this->agent();
        $remindAt = Carbon::parse('2026-08-10 14:00:00', 'Asia/Kolkata');

        $todo = $this->service->create($admin, [
            'title' => 'Hand off',
            'remind_at' => $remindAt,
        ]);

        $updated = $this->service->assign($admin, $todo, $assignee);

        $this->assertSame($assignee->id, $updated->assigned_to);
        $this->assertSame($assignee->id, $updated->reminders()->where('status', ReminderStatus::Pending)->first()?->user_id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => TodoService::EVENT_ASSIGNED,
            'auditable_id' => $todo->id,
        ]);
    }

    public function test_agent_cannot_assign(): void
    {
        $creator = $this->agent();
        $other = $this->agent();
        $todo = $this->service->create($creator, ['title' => 'Mine']);

        $this->expectException(AuthorizationException::class);

        $this->service->assign($creator, $todo, $other);
    }

    public function test_complete_sets_completed_at_and_cancels_pending_reminders(): void
    {
        $actor = $this->agent();
        $todo = $this->service->create($actor, [
            'title' => 'Finish me',
            'remind_at' => Carbon::parse('2026-08-10 14:00:00', 'Asia/Kolkata'),
        ]);

        $completed = $this->service->complete($actor, $todo);

        $this->assertSame(TodoStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertSame(
            0,
            Reminder::query()
                ->where('remindable_id', $todo->id)
                ->where('status', ReminderStatus::Pending->value)
                ->count(),
        );

        $this->assertDatabaseHas('audit_logs', [
            'event' => TodoService::EVENT_COMPLETED,
            'auditable_id' => $todo->id,
        ]);
    }

    public function test_reopen_clears_completed_at(): void
    {
        $actor = $this->agent();
        $todo = $this->service->create($actor, ['title' => 'Reopen me']);
        $this->service->complete($actor, $todo);

        $reopened = $this->service->reopen($actor, $todo->fresh());

        $this->assertSame(TodoStatus::Open, $reopened->status);
        $this->assertNull($reopened->completed_at);

        $this->assertDatabaseHas('audit_logs', [
            'event' => TodoService::EVENT_REOPENED,
            'auditable_id' => $todo->id,
        ]);
    }

    public function test_cancel_marks_cancelled_and_cancels_pending_reminders(): void
    {
        $actor = $this->agent();
        $todo = $this->service->create($actor, [
            'title' => 'Cancel me',
            'remind_at' => Carbon::parse('2026-08-10 14:00:00', 'Asia/Kolkata'),
        ]);

        $cancelled = $this->service->cancel($actor, $todo);

        $this->assertSame(TodoStatus::Cancelled, $cancelled->status);
        $this->assertSame(
            0,
            Reminder::query()
                ->where('remindable_id', $todo->id)
                ->where('status', ReminderStatus::Pending->value)
                ->count(),
        );

        $this->assertDatabaseHas('audit_logs', [
            'event' => TodoService::EVENT_CANCELLED,
            'auditable_id' => $todo->id,
        ]);
    }

    public function test_cancelled_todo_cannot_receive_new_reminder(): void
    {
        $actor = $this->agent();
        $todo = $this->service->create($actor, ['title' => 'No reminders']);
        $this->service->cancel($actor, $todo);

        try {
            $this->service->scheduleReminder(
                $actor,
                $todo->fresh(),
                Carbon::parse('2026-08-10 14:00:00', 'Asia/Kolkata'),
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('remind_at', $exception->errors());
        }

        $this->assertSame(0, Reminder::query()->where('remindable_id', $todo->id)->where('status', ReminderStatus::Pending->value)->count());
    }

    public function test_cancelled_todo_cannot_be_updated(): void
    {
        $actor = $this->agent();
        $todo = $this->service->create($actor, ['title' => 'Locked']);
        $this->service->cancel($actor, $todo);

        $this->expectException(ValidationException::class);

        $this->service->update($actor, $todo->fresh(), [
            'title' => 'Nope',
        ]);
    }

    public function test_assignee_can_complete_but_cannot_cancel(): void
    {
        $admin = $this->admin();
        $assignee = $this->agent();

        $todo = $this->service->create($admin, [
            'title' => 'For assignee',
            'assigned_to' => $assignee->id,
        ]);

        $completed = $this->service->complete($assignee, $todo);
        $this->assertSame(TodoStatus::Completed, $completed->status);

        $todo = $this->service->create($admin, [
            'title' => 'Cannot cancel',
            'assigned_to' => $assignee->id,
        ]);

        $this->expectException(AuthorizationException::class);
        $this->service->cancel($assignee, $todo);
    }

    public function test_unrelated_user_cannot_update(): void
    {
        $creator = $this->agent();
        $other = $this->agent();
        $todo = $this->service->create($creator, ['title' => 'Private']);

        $this->expectException(AuthorizationException::class);

        $this->service->update($other, $todo, ['title' => 'Hijack']);
    }

    public function test_manage_user_can_update_others_todo(): void
    {
        $creator = $this->agent();
        $manager = $this->opsAdmin();
        $todo = $this->service->create($creator, ['title' => 'Team item']);

        $updated = $this->service->update($manager, $todo, [
            'title' => 'Managed',
        ]);

        $this->assertSame('Managed', $updated->title);
    }

    public function test_delete_removes_todo_and_cancels_pending_reminders(): void
    {
        $actor = $this->agent();
        $todo = $this->service->create($actor, [
            'title' => 'Delete me',
            'remind_at' => Carbon::parse('2026-08-10 14:00:00', 'Asia/Kolkata'),
        ]);
        $todoId = $todo->id;

        $this->service->delete($actor, $todo);

        $this->assertDatabaseMissing('todos', ['id' => $todoId]);
        $this->assertSame(
            0,
            Reminder::query()
                ->where('remindable_id', $todoId)
                ->where('status', ReminderStatus::Pending->value)
                ->count(),
        );
        $this->assertTrue(
            AuditLog::query()
                ->where('event', TodoService::EVENT_DELETED)
                ->where('auditable_id', $todoId)
                ->exists(),
        );
    }

    public function test_user_without_create_permission_cannot_create(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(RolePermissionSeeder::PERMISSION_TODOS_VIEW);

        $this->expectException(AuthorizationException::class);

        $this->service->create($user, ['title' => 'Denied']);
    }

    private function agent(): User
    {
        return $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
    }

    private function admin(): User
    {
        return $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    private function opsAdmin(): User
    {
        return $this->userWithRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
