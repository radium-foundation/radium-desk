<?php

namespace Tests\Feature\Todos;

use App\Enums\ReminderStatus;
use App\Enums\TodoStatus;
use App\Models\Reminder;
use App\Models\Todo;
use App\Models\User;
use App\Notifications\TodoReminderDueNotification;
use App\Services\Reminders\ReminderDispatchService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReminderDispatchTest extends TestCase
{
    use RefreshDatabase;

    private ReminderDispatchService $dispatchService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->dispatchService = app(ReminderDispatchService::class);

        Carbon::setTestNow(Carbon::parse('2026-08-09 10:00:00', 'Asia/Kolkata'));
        config(['app.timezone' => 'Asia/Kolkata']);
    }

    public function test_due_reminder_is_dispatched_with_todo_deep_link_and_type(): void
    {
        [$user, $todo, $reminder] = $this->dueReminderSetup();

        $stats = $this->dispatchService->dispatchDue(10);

        $this->assertSame(1, $stats['dispatched']);
        $this->assertSame(ReminderStatus::Dispatched, $reminder->fresh()->status);
        $this->assertNotNull($reminder->fresh()->notification_id);
        $this->assertNotNull($reminder->fresh()->dispatched_at);

        $notification = $user->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertSame(TodoReminderDueNotification::class, $notification->type);
        $this->assertSame('To-Do Reminder', $notification->data['title']);
        $this->assertSame($todo->title, $notification->data['message']);
        $this->assertSame(route('todos.show', $todo), $notification->data['url']);
        $this->assertSame(TodoReminderDueNotification::TYPE, $notification->data['type']);
        $this->assertSame($todo->id, $notification->data['todo_id']);
        $this->assertSame($reminder->id, $notification->data['reminder_id']);
    }

    public function test_future_reminder_is_not_dispatched(): void
    {
        [$user, $todo] = $this->openTodo();

        Reminder::factory()->forTodo($todo)->create([
            'user_id' => $user->id,
            'remind_at' => now()->addHour(),
            'status' => ReminderStatus::Pending,
            'idempotency_key' => 'todo-reminder.'.$todo->id.'.future',
        ]);

        $stats = $this->dispatchService->dispatchDue(10);

        $this->assertSame(0, $stats['claimed']);
        $this->assertSame(0, $stats['dispatched']);
        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_cancelled_reminder_is_not_dispatched(): void
    {
        [$user, $todo] = $this->openTodo();

        Reminder::factory()->forTodo($todo)->create([
            'user_id' => $user->id,
            'remind_at' => now()->subMinute(),
            'status' => ReminderStatus::Cancelled,
            'idempotency_key' => 'todo-reminder.'.$todo->id.'.cancelled',
        ]);

        $stats = $this->dispatchService->dispatchDue(10);

        $this->assertSame(0, $stats['claimed']);
        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_completed_todo_reminder_is_skipped(): void
    {
        [$user, $todo] = $this->openTodo();
        $todo->update([
            'status' => TodoStatus::Completed,
            'completed_at' => now(),
        ]);

        $reminder = Reminder::factory()->forTodo($todo)->create([
            'user_id' => $user->id,
            'remind_at' => now()->subMinute(),
            'status' => ReminderStatus::Pending,
            'idempotency_key' => 'todo-reminder.'.$todo->id.'.completed-todo',
        ]);

        $stats = $this->dispatchService->dispatchDue(10);

        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(ReminderStatus::Skipped, $reminder->fresh()->status);
        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_exactly_one_notification_for_a_reminder_even_on_rerun(): void
    {
        [$user, $todo, $reminder] = $this->dueReminderSetup();

        $this->dispatchService->dispatchDue(10);
        $this->dispatchService->dispatchDue(10);

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(ReminderStatus::Dispatched, $reminder->fresh()->status);
    }

    public function test_concurrent_workers_cannot_dispatch_twice(): void
    {
        [$user, $todo, $reminder] = $this->dueReminderSetup();

        $first = $this->claimViaServiceReflection($reminder->id);
        $second = $this->claimViaServiceReflection($reminder->id);

        $this->assertNotNull($first);
        $this->assertNull($second);

        $outcome = $this->processViaServiceReflection($first);

        $this->assertSame('dispatched', $outcome);
        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(ReminderStatus::Dispatched, $reminder->fresh()->status);
    }

    public function test_failure_is_retryable_then_succeeds_without_duplicate(): void
    {
        [$user, $todo, $reminder] = $this->dueReminderSetup();

        $claimed = $this->claimViaServiceReflection($reminder->id);
        $this->assertNotNull($claimed);

        $outcome = $this->failViaServiceReflection($claimed, new \RuntimeException('temporary failure'));
        $this->assertSame('retried', $outcome);

        $fresh = $reminder->fresh();
        $this->assertSame(ReminderStatus::Pending, $fresh->status);
        $this->assertSame(1, $fresh->metadata['attempts'] ?? null);
        $this->assertNotEmpty($fresh->metadata['available_at'] ?? null);

        $fresh->fill([
            'metadata' => array_merge($fresh->metadata ?? [], [
                'available_at' => now()->subSecond()->toIso8601String(),
            ]),
        ])->save();

        $stats = $this->dispatchService->dispatchDue(10);

        $this->assertSame(1, $stats['dispatched']);
        $this->assertSame(ReminderStatus::Dispatched, $reminder->fresh()->status);
        $this->assertSame(1, $user->fresh()->notifications()->count());
    }

    public function test_max_attempts_marks_failed(): void
    {
        [$user, $todo, $reminder] = $this->dueReminderSetup();

        $reminder->fill([
            'metadata' => [
                'attempts' => ReminderDispatchService::MAX_ATTEMPTS - 1,
                'available_at' => now()->subSecond()->toIso8601String(),
            ],
        ])->save();

        $claimed = $this->claimViaServiceReflection($reminder->id);
        $this->assertNotNull($claimed);
        $this->assertSame(ReminderDispatchService::MAX_ATTEMPTS, $claimed->metadata['attempts'] ?? null);

        $outcome = $this->failViaServiceReflection($claimed, new \RuntimeException('permanent failure'));

        $this->assertSame('failed', $outcome);
        $this->assertSame(ReminderStatus::Failed, $reminder->fresh()->status);
        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_bounded_batch_processes_only_limit(): void
    {
        $user = $this->agent();

        for ($i = 0; $i < 5; $i++) {
            $todo = Todo::factory()->create([
                'created_by' => $user->id,
                'assigned_to' => $user->id,
                'title' => "Batch {$i}",
            ]);

            Reminder::factory()->forTodo($todo)->create([
                'user_id' => $user->id,
                'remind_at' => now()->subMinutes(5 - $i),
                'status' => ReminderStatus::Pending,
                'idempotency_key' => 'todo-reminder.'.$todo->id.'.batch-'.$i,
            ]);
        }

        $stats = $this->dispatchService->dispatchDue(2);

        $this->assertSame(2, $stats['claimed']);
        $this->assertSame(2, $stats['dispatched']);
        $this->assertSame(2, $user->notifications()->count());
        $this->assertSame(3, Reminder::query()->where('status', ReminderStatus::Pending)->count());
    }

    public function test_artisan_command_dispatches_due_reminders(): void
    {
        [$user, $todo, $reminder] = $this->dueReminderSetup();

        $this->artisan('reminders:dispatch-due', ['--limit' => 10])
            ->assertSuccessful();

        $this->assertSame(ReminderStatus::Dispatched, $reminder->fresh()->status);
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_recovered_existing_notification_marks_dispatched_without_duplicate(): void
    {
        [$user, $todo, $reminder] = $this->dueReminderSetup();

        $user->notify(new TodoReminderDueNotification($todo, $reminder));
        $existing = $user->notifications()->firstOrFail();

        $reminder->update(['status' => ReminderStatus::Pending]);

        $stats = $this->dispatchService->dispatchDue(10);

        $this->assertSame(1, $stats['dispatched']);
        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame((string) $existing->id, $reminder->fresh()->notification_id);
    }

    /**
     * @return array{0: User, 1: Todo, 2: Reminder}
     */
    private function dueReminderSetup(): array
    {
        [$user, $todo] = $this->openTodo();

        $reminder = Reminder::factory()->forTodo($todo)->create([
            'user_id' => $user->id,
            'remind_at' => now()->subMinute(),
            'status' => ReminderStatus::Pending,
            'idempotency_key' => 'todo-reminder.'.$todo->id.'.due',
        ]);

        return [$user, $todo, $reminder];
    }

    /**
     * @return array{0: User, 1: Todo}
     */
    private function openTodo(): array
    {
        $user = $this->agent();
        $todo = Todo::factory()->create([
            'created_by' => $user->id,
            'assigned_to' => $user->id,
            'title' => 'Remind this task',
            'status' => TodoStatus::Open,
        ]);

        return [$user, $todo];
    }

    private function agent(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    private function claimViaServiceReflection(int $reminderId): ?Reminder
    {
        $reminder = Reminder::query()->findOrFail($reminderId);
        $method = new \ReflectionMethod(ReminderDispatchService::class, 'claimReminder');

        return $method->invoke($this->dispatchService, $reminder);
    }

    private function processViaServiceReflection(Reminder $reminder): string
    {
        $method = new \ReflectionMethod(ReminderDispatchService::class, 'processClaimedReminder');

        return $method->invoke($this->dispatchService, $reminder);
    }

    private function failViaServiceReflection(Reminder $reminder, \Throwable $exception): string
    {
        $method = new \ReflectionMethod(ReminderDispatchService::class, 'handleDispatchFailure');

        return $method->invoke($this->dispatchService, $reminder, $exception);
    }
}
