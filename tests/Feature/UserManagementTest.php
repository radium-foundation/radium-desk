<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Notifications\TransactionCompletedNotification;
use App\Services\ServiceCaseAssignmentService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    private function configureAssignmentSettings(int $primaryId, int $fallbackId): void
    {
        app(SettingService::class)->setMany([
            'assignment.timezone' => 'Asia/Kolkata',
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $primaryId,
            'assignment.night_shift_admin_user_id' => (string) $primaryId,
            'assignment.fallback_admin_1_user_id' => (string) $fallbackId,
            'assignment.fallback_admin_2_user_id' => '',
        ]);
    }

    private function createAdmin(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'first_name' => 'Desk',
            'last_name' => 'Admin',
            'email' => 'desk-admin@test.com',
            'is_active' => true,
        ], $overrides));
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createSuperAdmin(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'super@test.com',
            'is_active' => true,
        ], $overrides));
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }

    public function test_agent_cannot_access_user_management(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)->get(route('users.index'))->assertForbidden();
        $this->actingAs($agent)->get(route('users.create'))->assertForbidden();
    }

    public function test_admin_can_create_user(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->post(route('users.store'), [
            'first_name' => 'New',
            'last_name' => 'Agent',
            'email' => 'newagent@test.com',
            'role' => RolePermissionSeeder::ROLE_AGENT,
            'password' => 'password',
            'password_confirmation' => 'password',
            'is_active' => '1',
        ])
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('status', 'user-created');

        $created = User::query()->where('email', 'newagent@test.com')->first();
        $this->assertNotNull($created);
        $this->assertSame('New', $created->first_name);
        $this->assertTrue($created->hasRole(RolePermissionSeeder::ROLE_AGENT));

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.created',
            'auditable_type' => $created->getMorphClass(),
            'auditable_id' => $created->id,
        ]);
    }

    public function test_admin_can_edit_user(): void
    {
        $admin = $this->createAdmin();
        $target = User::factory()->create([
            'first_name' => 'Edit',
            'last_name' => 'Me',
            'email' => 'editme@test.com',
        ]);
        $target->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($admin)->put(route('users.update', $target), [
            'first_name' => 'Edited',
            'last_name' => 'User',
            'email' => 'edited@test.com',
            'role' => RolePermissionSeeder::ROLE_ADMIN,
            'is_active' => '1',
        ])
            ->assertRedirect(route('users.edit', $target))
            ->assertSessionHas('status', 'user-updated');

        $target->refresh();
        $this->assertSame('Edited', $target->first_name);
        $this->assertSame('edited@test.com', $target->email);
        $this->assertTrue($target->hasRole(RolePermissionSeeder::ROLE_ADMIN));

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.updated',
            'auditable_type' => $target->getMorphClass(),
            'auditable_id' => $target->id,
        ]);
    }

    public function test_admin_can_deactivate_user(): void
    {
        $admin = $this->createAdmin();
        $target = User::factory()->create(['is_active' => true]);
        $target->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($admin)->patch(route('users.status.update', $target), [
            'is_active' => '0',
        ])
            ->assertRedirect(route('users.edit', $target))
            ->assertSessionHas('status', 'user-deactivated');

        $this->assertFalse($target->fresh()->is_active);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.deactivated',
            'auditable_type' => $target->getMorphClass(),
            'auditable_id' => $target->id,
        ]);
    }

    public function test_admin_can_reset_user_password(): void
    {
        $admin = $this->createAdmin();
        $target = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $target->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($admin)->patch(route('users.password-reset.update', $target), [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
            ->assertRedirect(route('users.edit', $target))
            ->assertSessionHas('status', 'user-password-reset');

        $this->assertTrue(Hash::check('new-password', $target->fresh()->password));

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'password.reset',
            'auditable_type' => $target->getMorphClass(),
            'auditable_id' => $target->id,
        ]);
    }

    public function test_superadmin_can_delete_user(): void
    {
        $superadmin = $this->createSuperAdmin();
        $target = User::factory()->create();
        $target->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($superadmin)->delete(route('users.destroy', $target))
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('status', 'user-deleted');

        $this->assertSoftDeleted('users', ['id' => $target->id]);
    }

    public function test_superadmin_cannot_delete_self(): void
    {
        $superadmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)->delete(route('users.destroy', $superadmin))
            ->assertForbidden();
    }

    public function test_admin_cannot_delete_user(): void
    {
        $admin = $this->createAdmin();
        $target = User::factory()->create();
        $target->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($admin)->delete(route('users.destroy', $target))
            ->assertForbidden();
    }

    public function test_admin_cannot_edit_superadmin(): void
    {
        $admin = $this->createAdmin();
        $superadmin = $this->createSuperAdmin(['email' => 'protected-super@test.com']);

        $this->actingAs($admin)->get(route('users.edit', $superadmin))->assertForbidden();

        $this->actingAs($admin)->put(route('users.update', $superadmin), [
            'first_name' => 'Hacked',
            'last_name' => 'User',
            'email' => 'hacked@test.com',
            'role' => RolePermissionSeeder::ROLE_ADMIN,
            'is_active' => '1',
        ])->assertForbidden();
    }

    public function test_admin_cannot_assign_superadmin_role(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->post(route('users.store'), [
            'first_name' => 'Bad',
            'last_name' => 'Role',
            'email' => 'badrole@test.com',
            'role' => RolePermissionSeeder::ROLE_SUPERADMIN,
            'password' => 'password',
            'password_confirmation' => 'password',
            'is_active' => '1',
        ])->assertSessionHasErrors('role');
    }

    public function test_assignment_skips_inactive_admin(): void
    {
        $primary = User::factory()->create([
            'email' => 'primary@radiumbox.com',
            'is_active' => false,
        ]);
        $primary->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $fallback = User::factory()->create([
            'email' => 'fallback@radiumbox.com',
            'is_active' => true,
        ]);
        $fallback->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->configureAssignmentSettings($primary->id, $fallback->id);

        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $assignee = app(ServiceCaseAssignmentService::class)->resolveAssignee();

        $this->assertTrue($assignee->is($fallback));

        Carbon::setTestNow();
    }

    public function test_inactive_user_does_not_receive_notifications(): void
    {
        Notification::fake();

        $admin = $this->createAdmin();
        $inactive = User::factory()->create(['is_active' => false]);
        $inactive->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-NOTIFY-1',
            'serial_number' => 'SN-NOTIFY-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => 'cf_user_mgmt_notify',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-NOTIFY-1',
            'category' => 'General',
            'source' => IncidentSource::Call->value,
            'title' => 'Notify test',
            'description' => 'Notify test.',
            'status' => 'open',
            'created_by' => $inactive->id,
            'assigned_to_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)->post(route('orders.transaction.store', $order), [
            'transaction_id' => 'TXN-123',
        ])->assertRedirect();

        Notification::assertNotSentTo($inactive, TransactionCompletedNotification::class);
    }
}
