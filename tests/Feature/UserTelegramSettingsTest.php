<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTelegramSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_agent_first_time_telegram_connection_forces_enabled_true(): void
    {
        $agent = $this->createAgent();

        $this->actingAs($agent)
            ->patch(route('profile.telegram.update'), [
                'telegram_chat_id' => '111222333',
            ])
            ->assertRedirect(route('profile.edit'));

        $agent->refresh();

        $this->assertSame('111222333', $agent->telegram_chat_id);
        $this->assertTrue($agent->telegram_notifications_enabled);
    }

    public function test_agent_with_existing_chat_id_cannot_change_chat_id(): void
    {
        $agent = $this->createAgent('444555666');

        $this->actingAs($agent)
            ->patch(route('profile.telegram.update'), [
                'telegram_chat_id' => '999888777',
            ])
            ->assertForbidden();

        $agent->refresh();

        $this->assertSame('444555666', $agent->telegram_chat_id);
    }

    public function test_agent_first_connect_with_crafted_disable_forces_enabled_true(): void
    {
        $agent = $this->createAgent();

        $this->actingAs($agent)
            ->patch(route('profile.telegram.update'), [
                'telegram_chat_id' => '111222333',
                'telegram_notifications_enabled' => '0',
            ])
            ->assertRedirect(route('profile.edit'));

        $agent->refresh();

        $this->assertSame('111222333', $agent->telegram_chat_id);
        $this->assertTrue($agent->telegram_notifications_enabled);
    }

    public function test_connected_agent_cannot_disable_via_crafted_request(): void
    {
        $agent = $this->createAgent('444555666');

        $this->actingAs($agent)
            ->patch(route('profile.telegram.update'), [
                'telegram_chat_id' => '444555666',
                'telegram_notifications_enabled' => '0',
            ])
            ->assertForbidden();

        $agent->refresh();

        $this->assertSame('444555666', $agent->telegram_chat_id);
        $this->assertTrue($agent->telegram_notifications_enabled);
    }

    public function test_admin_can_change_another_users_chat_id(): void
    {
        $admin = $this->createAdmin();
        $agent = $this->createAgent('111222333');

        $this->actingAs($admin)
            ->put(route('users.telegram.update', $agent), [
                'telegram_chat_id' => '777666555',
                'telegram_notifications_enabled' => '1',
            ])
            ->assertRedirect(route('users.edit', $agent));

        $agent->refresh();

        $this->assertSame('777666555', $agent->telegram_chat_id);
        $this->assertTrue($agent->telegram_notifications_enabled);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.telegram.updated',
            'auditable_type' => User::class,
            'auditable_id' => $agent->id,
            'user_id' => $admin->id,
        ]);

        $audit = AuditLog::query()
            ->where('event', 'user.telegram.updated')
            ->where('auditable_id', $agent->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertArrayNotHasKey('telegram_chat_id', (array) $audit->old_values);
        $this->assertArrayNotHasKey('telegram_chat_id', (array) $audit->new_values);
        $this->assertArrayHasKey('telegram_chat_id_fingerprint', (array) $audit->new_values);
    }

    public function test_admin_can_reset_telegram(): void
    {
        $admin = $this->createAdmin();
        $agent = $this->createAgent('111222333');

        $this->actingAs($admin)
            ->put(route('users.telegram.update', $agent), [
                'reset' => '1',
            ])
            ->assertRedirect(route('users.edit', $agent));

        $agent->refresh();

        $this->assertNull($agent->telegram_chat_id);
        $this->assertFalse($agent->telegram_notifications_enabled);
    }

    public function test_admin_can_disable_notifications_while_retaining_chat_id(): void
    {
        $admin = $this->createAdmin();
        $agent = $this->createAgent('111222333');

        $this->actingAs($admin)
            ->put(route('users.telegram.update', $agent), [
                'telegram_chat_id' => '111222333',
                'telegram_notifications_enabled' => '0',
            ])
            ->assertRedirect(route('users.edit', $agent));

        $agent->refresh();

        $this->assertSame('111222333', $agent->telegram_chat_id);
        $this->assertFalse($agent->telegram_notifications_enabled);
    }

    public function test_clearing_chat_id_disables_notifications(): void
    {
        $admin = $this->createAdmin();
        $agent = $this->createAgent('111222333');

        $this->actingAs($admin)
            ->put(route('users.telegram.update', $agent), [
                'telegram_chat_id' => '',
                'telegram_notifications_enabled' => '1',
            ])
            ->assertRedirect(route('users.edit', $agent));

        $agent->refresh();

        $this->assertNull($agent->telegram_chat_id);
        $this->assertFalse($agent->telegram_notifications_enabled);
    }

    public function test_agent_cannot_use_admin_telegram_route(): void
    {
        $agent = $this->createAgent();
        $otherAgent = $this->createAgent('222333444');

        $this->actingAs($agent)
            ->put(route('users.telegram.update', $otherAgent), [
                'telegram_chat_id' => '999888777',
                'telegram_notifications_enabled' => '1',
            ])
            ->assertForbidden();
    }

    public function test_non_superadmin_cannot_update_superadmin_telegram_settings(): void
    {
        $admin = $this->createAdmin();
        $superadmin = User::factory()->create([
            'telegram_chat_id' => '100200300',
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->actingAs($admin)
            ->put(route('users.telegram.update', $superadmin), [
                'telegram_chat_id' => '999888777',
                'telegram_notifications_enabled' => '1',
            ])
            ->assertForbidden();
    }

    private function createAgent(?string $chatId = null): User
    {
        $agent = User::factory()->create([
            'telegram_chat_id' => $chatId,
            'telegram_notifications_enabled' => filled($chatId),
            'is_active' => true,
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        return $agent;
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }
}
