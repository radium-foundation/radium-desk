<?php

namespace Tests\Feature\Todos;

use App\Enums\ReminderStatus;
use App\Enums\TodoPriority;
use App\Enums\TodoStatus;
use App\Models\Reminder;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class TodoReminderFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_todos_and_reminders_tables_exist_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('todos'));
        $this->assertTrue(Schema::hasTable('reminders'));

        foreach ([
            'id',
            'created_by',
            'assigned_to',
            'title',
            'description',
            'priority',
            'status',
            'due_at',
            'completed_at',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('todos', $column), "Missing todos.{$column}");
        }

        foreach ([
            'id',
            'remindable_type',
            'remindable_id',
            'user_id',
            'remind_at',
            'status',
            'dispatched_at',
            'notification_id',
            'idempotency_key',
            'metadata',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('reminders', $column), "Missing reminders.{$column}");
        }
    }

    public function test_todos_indexes_cover_required_lookup_paths(): void
    {
        $this->assertTrue($this->hasIndexOnColumns('todos', ['assigned_to', 'status']));
        $this->assertTrue($this->hasIndexOnColumns('todos', ['created_by', 'status']));
        $this->assertTrue($this->hasIndexOnColumns('todos', ['due_at']));
        $this->assertTrue($this->hasIndexOnColumns('todos', ['status', 'due_at']));
    }

    public function test_reminders_indexes_cover_dispatch_and_morph_paths(): void
    {
        $this->assertTrue($this->hasIndexOnColumns('reminders', ['status', 'remind_at']));
        $this->assertTrue($this->hasIndexOnColumns('reminders', ['user_id', 'status']));
        $this->assertTrue($this->hasIndexOnColumns('reminders', ['remindable_type', 'remindable_id']));
        $this->assertTrue($this->hasUniqueIndexOnColumns('reminders', ['idempotency_key']));
    }

    public function test_todo_status_and_priority_enum_values(): void
    {
        $this->assertSame(['open', 'completed', 'cancelled'], TodoStatus::values());
        $this->assertSame(['low', 'normal', 'high'], TodoPriority::values());
        $this->assertSame('Open', TodoStatus::Open->label());
        $this->assertSame('High', TodoPriority::High->label());
    }

    public function test_reminder_status_enum_values(): void
    {
        $this->assertSame(
            ['pending', 'processing', 'dispatched', 'cancelled', 'skipped', 'failed'],
            ReminderStatus::values(),
        );
        $this->assertSame('Pending', ReminderStatus::Pending->label());
        $this->assertSame('Dispatched', ReminderStatus::Dispatched->label());
    }

    public function test_todo_persists_required_and_nullable_fields(): void
    {
        $creator = User::factory()->create();
        $assignee = User::factory()->create();

        $todo = Todo::query()->create([
            'created_by' => $creator->id,
            'assigned_to' => $assignee->id,
            'title' => 'Follow up with customer',
            'description' => null,
            'priority' => TodoPriority::High,
            'status' => TodoStatus::Open,
            'due_at' => null,
            'completed_at' => null,
        ]);

        $todo->refresh();

        $this->assertSame('Follow up with customer', $todo->title);
        $this->assertNull($todo->description);
        $this->assertSame(TodoPriority::High, $todo->priority);
        $this->assertSame(TodoStatus::Open, $todo->status);
        $this->assertNull($todo->due_at);
        $this->assertNull($todo->completed_at);
        $this->assertNotNull($todo->created_at);
        $this->assertNotNull($todo->updated_at);
    }

    public function test_todo_title_is_required_at_database_layer(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        Todo::query()->create([
            'created_by' => $user->id,
            'assigned_to' => $user->id,
            'title' => null,
            'priority' => TodoPriority::Normal,
            'status' => TodoStatus::Open,
        ]);
    }

    public function test_todo_relationships_to_creator_assignee_and_reminders(): void
    {
        $creator = User::factory()->create();
        $assignee = User::factory()->create();

        $todo = Todo::factory()->create([
            'created_by' => $creator->id,
            'assigned_to' => $assignee->id,
        ]);

        $reminder = Reminder::factory()->forTodo($todo)->create([
            'idempotency_key' => 'todo-reminder.'.$todo->id.'.relationship',
        ]);

        $todo->refresh();

        $this->assertTrue($todo->creator->is($creator));
        $this->assertTrue($todo->assignee->is($assignee));
        $this->assertTrue($todo->reminders->contains($reminder));
        $this->assertTrue($reminder->remindable->is($todo));
        $this->assertTrue($reminder->user->is($assignee));
    }

    public function test_reminder_allows_nullable_delivery_fields_and_stores_notification_uuid(): void
    {
        $todo = Todo::factory()->create();
        $notificationId = (string) Str::uuid();

        $reminder = Reminder::factory()->forTodo($todo)->create([
            'idempotency_key' => 'todo-reminder.'.$todo->id.'.nullable-fields',
            'dispatched_at' => null,
            'notification_id' => null,
            'metadata' => null,
        ]);

        $this->assertNull($reminder->dispatched_at);
        $this->assertNull($reminder->notification_id);
        $this->assertNull($reminder->metadata);
        $this->assertSame(ReminderStatus::Pending, $reminder->status);

        $reminder->update([
            'status' => ReminderStatus::Dispatched,
            'dispatched_at' => now(),
            'notification_id' => $notificationId,
            'metadata' => ['source' => 'todo'],
        ]);

        $reminder->refresh();

        $this->assertSame(ReminderStatus::Dispatched, $reminder->status);
        $this->assertNotNull($reminder->dispatched_at);
        $this->assertSame($notificationId, $reminder->notification_id);
        $this->assertSame(['source' => 'todo'], $reminder->metadata);
    }

    public function test_reminder_idempotency_key_is_unique(): void
    {
        $todo = Todo::factory()->create();
        $key = 'todo-reminder.'.$todo->id.'.unique-key';

        Reminder::factory()->forTodo($todo)->create([
            'idempotency_key' => $key,
        ]);

        $this->expectException(QueryException::class);

        Reminder::factory()->forTodo($todo)->create([
            'idempotency_key' => $key,
        ]);
    }

    public function test_todo_factory_defaults_to_open_normal_personal_task(): void
    {
        $todo = Todo::factory()->create();

        $this->assertSame(TodoStatus::Open, $todo->status);
        $this->assertSame(TodoPriority::Normal, $todo->priority);
        $this->assertSame($todo->created_by, $todo->assigned_to);
        $this->assertNull($todo->description);
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasIndexOnColumns(string $table, array $columns): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'] ?? []) === $columns) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasUniqueIndexOnColumns(string $table, array $columns): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'] ?? []) === $columns && ($index['unique'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }
}
