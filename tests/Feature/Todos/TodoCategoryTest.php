<?php

namespace Tests\Feature\Todos;

use App\Models\Todo;
use App\Models\TodoCategory;
use App\Models\User;
use App\Services\Todos\TodoCategoryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TodoCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_can_create_and_list_todo_categories(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('todo-categories.index'))
            ->assertOk()
            ->assertSee('To-Do Categories')
            ->assertSee('Add category');

        $this->actingAs($admin)
            ->post(route('todo-categories.store'), ['name' => 'Follow-up'])
            ->assertRedirect(route('todo-categories.index'))
            ->assertSessionHas('status', 'todo-category-created');

        $this->assertDatabaseHas('todo_categories', [
            'name' => 'Follow-up',
            'is_active' => true,
        ]);
    }

    public function test_duplicate_category_name_is_rejected(): void
    {
        $admin = $this->admin();
        TodoCategory::factory()->create(['name' => 'Follow-up']);

        $this->actingAs($admin)
            ->from(route('todo-categories.index'))
            ->post(route('todo-categories.store'), ['name' => 'Follow-up'])
            ->assertRedirect(route('todo-categories.index'))
            ->assertSessionHasErrors('name');
    }

    public function test_inactive_category_cannot_be_hard_deleted_and_toggle_deactivates(): void
    {
        $admin = $this->admin();
        $agent = $this->agent();
        $category = TodoCategory::factory()->create(['name' => 'Customer call']);

        $todo = Todo::factory()->create([
            'created_by' => $agent->id,
            'assigned_to' => $agent->id,
            'todo_category_id' => $category->id,
        ]);

        $this->actingAs($admin)
            ->patch(route('todo-categories.toggle', $category))
            ->assertRedirect(route('todo-categories.index'))
            ->assertSessionHas('status', 'todo-category-deactivated');

        $this->assertFalse($category->fresh()->is_active);
        $this->assertDatabaseHas('todos', [
            'id' => $todo->id,
            'todo_category_id' => $category->id,
        ]);
        $this->assertSame(1, TodoCategory::query()->count());
    }

    public function test_deactivated_category_can_be_reactivated(): void
    {
        $admin = $this->admin();
        $category = TodoCategory::factory()->inactive()->create(['name' => 'Merged later']);

        $this->actingAs($admin)
            ->patch(route('todo-categories.toggle', $category))
            ->assertRedirect(route('todo-categories.index'))
            ->assertSessionHas('status', 'todo-category-activated');

        $this->assertTrue($category->fresh()->is_active);
    }

    public function test_agent_cannot_manage_todo_categories(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent)
            ->get(route('todo-categories.index'))
            ->assertForbidden();

        $this->actingAs($agent)
            ->post(route('todo-categories.store'), ['name' => 'Illegal'])
            ->assertForbidden();
    }

    public function test_todo_form_accepts_active_category_and_rejects_inactive(): void
    {
        $agent = $this->agent();
        $active = TodoCategory::factory()->create(['name' => 'Active label']);
        $inactive = TodoCategory::factory()->inactive()->create(['name' => 'Retired label']);

        $this->actingAs($agent)
            ->post(route('todos.store'), [
                'title' => 'Categorized',
                'todo_category_id' => $active->id,
            ])
            ->assertRedirect();

        $todo = Todo::query()->where('title', 'Categorized')->firstOrFail();
        $this->assertSame($active->id, $todo->todo_category_id);

        $this->actingAs($agent)
            ->post(route('todos.store'), [
                'title' => 'Should fail',
                'todo_category_id' => $inactive->id,
            ])
            ->assertSessionHasErrors('todo_category_id');
    }

    public function test_existing_todo_keeps_inactive_category_on_edit(): void
    {
        $agent = $this->agent();
        $category = TodoCategory::factory()->create(['name' => 'Keep me']);
        $todo = Todo::factory()->create([
            'created_by' => $agent->id,
            'assigned_to' => $agent->id,
            'todo_category_id' => $category->id,
            'title' => 'Still categorized',
        ]);

        $category->update(['is_active' => false]);

        $this->actingAs($agent)
            ->put(route('todos.update', $todo), [
                'title' => 'Still categorized',
                'priority' => $todo->priority->value,
                'todo_category_id' => $category->id,
            ])
            ->assertRedirect(route('todos.show', $todo));

        $this->assertSame($category->id, $todo->fresh()->todo_category_id);
    }

    public function test_index_can_filter_by_category(): void
    {
        $agent = $this->agent();
        $matchCategory = TodoCategory::factory()->create(['name' => 'Match']);
        $otherCategory = TodoCategory::factory()->create(['name' => 'Other']);

        $match = Todo::factory()->create([
            'created_by' => $agent->id,
            'assigned_to' => $agent->id,
            'todo_category_id' => $matchCategory->id,
            'title' => 'Matching todo',
        ]);
        Todo::factory()->create([
            'created_by' => $agent->id,
            'assigned_to' => $agent->id,
            'todo_category_id' => $otherCategory->id,
            'title' => 'Other todo',
        ]);

        $this->actingAs($agent)
            ->get(route('todos.index', ['category' => $matchCategory->id]))
            ->assertOk()
            ->assertSee('Matching todo')
            ->assertDontSee('Other todo');

        $this->assertTrue($match->exists);
    }

    public function test_category_service_records_audit_events(): void
    {
        $admin = $this->admin();
        $service = app(TodoCategoryService::class);

        $category = $service->create($admin, 'Audit me');
        $service->update($admin, $category, 'Audit renamed');
        $service->toggle($admin, $category);

        $this->assertDatabaseHas('audit_logs', [
            'event' => TodoCategoryService::EVENT_CREATED,
            'auditable_id' => $category->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => TodoCategoryService::EVENT_UPDATED,
            'auditable_id' => $category->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => TodoCategoryService::EVENT_DEACTIVATED,
            'auditable_id' => $category->id,
        ]);
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
