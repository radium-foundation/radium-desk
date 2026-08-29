<?php

namespace Tests\Feature\Todos;

use App\Models\Todo;
use App\Models\TodoCategory;
use App\Models\User;
use App\Notifications\TodoAssignedNotification;
use App\Services\Todos\TodoNotificationService;
use App\Services\Todos\TodoService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TodoNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config(['services.telegram.bot_token' => 'test-bot-token']);
        $this->enableTelegramNotifications();
    }

    public function test_creating_a_todo_sends_telegram_to_the_assignee(): void
    {
        Notification::fake();

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 91],
            ], 200),
        ]);

        $actor = $this->superAdmin([
            'telegram_chat_id' => '111222333',
            'telegram_notifications_enabled' => true,
        ]);
        $assignee = $this->superAdmin([
            'telegram_chat_id' => '444555666',
            'telegram_notifications_enabled' => true,
        ]);
        $category = TodoCategory::factory()->create(['name' => 'Follow-up']);

        $this->actingAs($actor)
            ->post(route('todos.store'), [
                'title' => 'Call the customer',
                'assigned_to' => $assignee->id,
                'todo_category_id' => $category->id,
            ])
            ->assertRedirect();

        Notification::assertSentTo($assignee, TodoAssignedNotification::class);
        Http::assertSent(function ($request) use ($assignee): bool {
            return str_contains($request->url(), 'api.telegram.org')
                && $request['chat_id'] === $assignee->telegram_chat_id
                && str_contains((string) $request['text'], 'To-Do created')
                && str_contains((string) $request['text'], 'Call the customer')
                && str_contains((string) $request['text'], 'Category: Follow-up');
        });

        $todo = Todo::query()->where('title', 'Call the customer')->firstOrFail();
        $this->assertDatabaseHas('audit_logs', [
            'event' => TodoNotificationService::EVENT_NOTIFIED,
            'auditable_id' => $todo->id,
        ]);
    }

    public function test_self_created_todo_still_sends_telegram(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 92],
            ], 200),
        ]);

        $actor = $this->superAdmin([
            'telegram_chat_id' => '777888999',
            'telegram_notifications_enabled' => true,
        ]);

        app(TodoService::class)->create($actor, [
            'title' => 'Personal follow-up',
        ]);

        Http::assertSent(function ($request) use ($actor): bool {
            return $request['chat_id'] === $actor->telegram_chat_id
                && str_contains((string) $request['text'], 'To-Do created')
                && str_contains((string) $request['text'], 'Personal follow-up');
        });
    }

    public function test_assignment_sends_telegram_to_the_new_assignee(): void
    {
        Notification::fake();

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 93],
            ], 200),
        ]);

        $actor = $this->superAdmin([
            'telegram_chat_id' => '100100100',
            'telegram_notifications_enabled' => true,
        ]);
        $assignee = $this->superAdmin([
            'telegram_chat_id' => '200200200',
            'telegram_notifications_enabled' => true,
        ]);

        $todo = Todo::factory()->create([
            'created_by' => $actor->id,
            'assigned_to' => $actor->id,
            'title' => 'Delegated later',
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 94],
            ], 200),
        ]);

        app(TodoService::class)->assign($actor, $todo, $assignee);

        Notification::assertSentTo($assignee, TodoAssignedNotification::class);
        Http::assertSent(function ($request) use ($assignee): bool {
            return $request['chat_id'] === $assignee->telegram_chat_id
                && str_contains((string) $request['text'], 'To-Do assigned')
                && str_contains((string) $request['text'], 'Delegated later');
        });
    }

    public function test_create_succeeds_when_telegram_is_not_configured(): void
    {
        config(['services.telegram.bot_token' => '']);
        $this->disableTelegramNotifications();

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $todo = app(TodoService::class)->create($agent, [
            'title' => 'No telegram path',
        ]);

        $this->assertSame('No telegram path', $todo->title);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => TodoNotificationService::EVENT_NOTIFIED,
            'auditable_id' => $todo->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function superAdmin(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'is_active' => true,
        ], $overrides));
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }
}
