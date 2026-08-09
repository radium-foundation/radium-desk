<?php

namespace Tests\Feature\Todos;

use App\Enums\ReminderStatus;
use App\Enums\TodoPriority;
use App\Enums\TodoStatus;
use App\Models\Reminder;
use App\Models\Todo;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TodoControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Carbon::setTestNow(Carbon::parse('2026-08-09 10:00:00', 'Asia/Kolkata'));
        config(['app.timezone' => 'Asia/Kolkata']);
    }

    public function test_index_requires_authentication(): void
    {
        $this->get(route('todos.index'))->assertRedirect(route('login'));
    }

    public function test_agent_can_view_index_and_create_form(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent)
            ->get(route('todos.index'))
            ->assertOk()
            ->assertSee('To-Dos')
            ->assertSee(route('todos.create'), false);

        $this->actingAs($agent)
            ->get(route('todos.create'))
            ->assertOk()
            ->assertSee('New to-do');
    }

    public function test_store_creates_todo_with_due_and_reminder_via_service(): void
    {
        $agent = $this->agent();

        $response = $this->actingAs($agent)->post(route('todos.store'), [
            'title' => 'Follow up',
            'description' => 'Call back',
            'priority' => TodoPriority::High->value,
            'due_at' => '2026-08-10T15:00',
            'remind_at' => '2026-08-10T14:00',
        ]);

        $todo = Todo::query()->where('title', 'Follow up')->firstOrFail();

        $response->assertRedirect(route('todos.show', $todo));

        $this->assertSame($agent->id, $todo->created_by);
        $this->assertSame($agent->id, $todo->assigned_to);
        $this->assertSame(TodoPriority::High, $todo->priority);
        $this->assertNotNull($todo->due_at);
        $this->assertDatabaseHas('reminders', [
            'remindable_id' => $todo->id,
            'user_id' => $agent->id,
            'status' => ReminderStatus::Pending->value,
        ]);
    }

    public function test_store_validation_requires_title(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent)
            ->from(route('todos.create'))
            ->post(route('todos.store'), [
                'title' => '',
                'priority' => TodoPriority::Normal->value,
            ])
            ->assertRedirect(route('todos.create'))
            ->assertSessionHasErrors(['title']);
    }

    public function test_agent_cannot_create_assigned_to_another_user(): void
    {
        $agent = $this->agent();
        $other = $this->agent();

        $this->actingAs($agent)
            ->post(route('todos.store'), [
                'title' => 'Assigned illegally',
                'assigned_to' => $other->id,
                'priority' => TodoPriority::Normal->value,
            ])
            ->assertForbidden();
    }

    public function test_admin_can_create_and_assign(): void
    {
        $admin = $this->admin();
        $assignee = $this->agent();

        $this->actingAs($admin)
            ->post(route('todos.store'), [
                'title' => 'Delegated',
                'assigned_to' => $assignee->id,
                'priority' => TodoPriority::Normal->value,
            ])
            ->assertRedirect();

        $todo = Todo::query()->where('title', 'Delegated')->firstOrFail();
        $this->assertSame($assignee->id, $todo->assigned_to);
    }

    public function test_update_changes_fields_and_reschedules_reminder(): void
    {
        $agent = $this->agent();
        $todo = Todo::factory()->create([
            'created_by' => $agent->id,
            'assigned_to' => $agent->id,
            'title' => 'Draft',
        ]);

        Reminder::factory()->forTodo($todo)->create([
            'idempotency_key' => 'todo-reminder.'.$todo->id.'.old',
            'remind_at' => Carbon::parse('2026-08-10 12:00:00', 'Asia/Kolkata'),
        ]);

        $this->actingAs($agent)
            ->put(route('todos.update', $todo), [
                'title' => 'Updated title',
                'description' => 'Notes',
                'priority' => TodoPriority::Low->value,
                'due_at' => '2026-08-11T09:00',
                'remind_at' => '2026-08-11T08:00',
            ])
            ->assertRedirect(route('todos.show', $todo));

        $todo->refresh();
        $this->assertSame('Updated title', $todo->title);
        $this->assertSame(TodoPriority::Low, $todo->priority);
        $this->assertSame(
            1,
            Reminder::query()
                ->where('remindable_id', $todo->id)
                ->where('status', ReminderStatus::Pending->value)
                ->count(),
        );
    }

    public function test_complete_and_reopen_via_routes(): void
    {
        $agent = $this->agent();
        $todo = Todo::factory()->create([
            'created_by' => $agent->id,
            'assigned_to' => $agent->id,
            'status' => TodoStatus::Open,
        ]);

        $this->actingAs($agent)
            ->post(route('todos.complete', $todo))
            ->assertRedirect(route('todos.show', $todo));

        $todo->refresh();
        $this->assertSame(TodoStatus::Completed, $todo->status);
        $this->assertNotNull($todo->completed_at);

        $this->actingAs($agent)
            ->post(route('todos.reopen', $todo))
            ->assertRedirect(route('todos.show', $todo));

        $todo->refresh();
        $this->assertSame(TodoStatus::Open, $todo->status);
        $this->assertNull($todo->completed_at);
    }

    public function test_cancel_and_delete_via_routes(): void
    {
        $agent = $this->agent();
        $todo = Todo::factory()->create([
            'created_by' => $agent->id,
            'assigned_to' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->post(route('todos.cancel', $todo))
            ->assertRedirect(route('todos.show', $todo));

        $this->assertSame(TodoStatus::Cancelled, $todo->fresh()->status);

        $this->actingAs($agent)
            ->delete(route('todos.destroy', $todo))
            ->assertRedirect(route('todos.index'));

        $this->assertDatabaseMissing('todos', ['id' => $todo->id]);
    }

    public function test_unrelated_user_cannot_view_or_update(): void
    {
        $creator = $this->agent();
        $other = $this->agent();
        $todo = Todo::factory()->create([
            'created_by' => $creator->id,
            'assigned_to' => $creator->id,
            'title' => 'Secret',
        ]);

        $this->actingAs($other)
            ->get(route('todos.show', $todo))
            ->assertForbidden();

        $this->actingAs($other)
            ->put(route('todos.update', $todo), [
                'title' => 'Hijacked',
                'priority' => TodoPriority::Normal->value,
            ])
            ->assertForbidden();
    }

    public function test_assignee_can_view_and_complete_but_cannot_cancel(): void
    {
        $admin = $this->admin();
        $assignee = $this->agent();
        $todo = Todo::factory()->create([
            'created_by' => $admin->id,
            'assigned_to' => $assignee->id,
            'title' => 'Assigned work',
        ]);

        $this->actingAs($assignee)
            ->get(route('todos.show', $todo))
            ->assertOk()
            ->assertSee('Assigned work');

        $this->actingAs($assignee)
            ->post(route('todos.complete', $todo))
            ->assertRedirect();

        $todo = Todo::factory()->create([
            'created_by' => $admin->id,
            'assigned_to' => $assignee->id,
        ]);

        $this->actingAs($assignee)
            ->post(route('todos.cancel', $todo))
            ->assertForbidden();
    }

    public function test_admin_can_assign_via_route(): void
    {
        $admin = $this->admin();
        $assignee = $this->agent();
        $todo = Todo::factory()->create([
            'created_by' => $admin->id,
            'assigned_to' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('todos.assign', $todo), [
                'assigned_to' => $assignee->id,
            ])
            ->assertRedirect(route('todos.show', $todo));

        $this->assertSame($assignee->id, $todo->fresh()->assigned_to);
    }

    public function test_agent_cannot_assign_via_route(): void
    {
        $agent = $this->agent();
        $other = $this->agent();
        $todo = Todo::factory()->create([
            'created_by' => $agent->id,
            'assigned_to' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->post(route('todos.assign', $todo), [
                'assigned_to' => $other->id,
            ])
            ->assertForbidden();
    }

    public function test_show_renders_overdue_badge_for_past_due_open_todo(): void
    {
        $agent = $this->agent();
        $todo = Todo::factory()->create([
            'created_by' => $agent->id,
            'assigned_to' => $agent->id,
            'status' => TodoStatus::Open,
            'due_at' => Carbon::parse('2026-08-08 09:00:00', 'Asia/Kolkata'),
            'title' => 'Late item',
        ]);

        $this->actingAs($agent)
            ->get(route('todos.show', $todo))
            ->assertOk()
            ->assertSee('Late item')
            ->assertSee('Overdue');
    }

    public function test_user_without_todo_permission_cannot_access_index(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)
            ->get(route('todos.index'))
            ->assertForbidden();
    }

    public function test_sidebar_includes_todos_for_agent(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent)
            ->get(route('todos.index'))
            ->assertOk()
            ->assertSee(route('todos.index'), false)
            ->assertSee('To-Dos');
    }

    private function agent(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }
}
