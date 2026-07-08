<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\TeamAvailabilityStatus;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\AuditLog;
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
            'roles' => [RolePermissionSeeder::ROLE_AGENT],
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
            'roles' => [RolePermissionSeeder::ROLE_ADMIN],
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

    public function test_admin_can_save_bonvoice_extension_on_create(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->post(route('users.store'), [
            'first_name' => 'Call',
            'last_name' => 'Agent',
            'email' => 'callagent@test.com',
            'roles' => [RolePermissionSeeder::ROLE_AGENT],
            'password' => 'password',
            'password_confirmation' => 'password',
            'is_active' => '1',
            'bonvoice_extension' => '08448423017',
        ])
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('status', 'user-created');

        $created = User::query()->where('email', 'callagent@test.com')->first();
        $this->assertNotNull($created);
        $this->assertSame('08448423017', $created->bonvoice_extension);
    }

    public function test_admin_can_save_bonvoice_extension_on_update(): void
    {
        $admin = $this->createAdmin();
        $target = User::factory()->create([
            'email' => 'extension-update@test.com',
            'bonvoice_extension' => null,
        ]);
        $target->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($admin)->put(route('users.update', $target), [
            'first_name' => $target->first_name,
            'last_name' => $target->last_name,
            'email' => $target->email,
            'roles' => [RolePermissionSeeder::ROLE_AGENT],
            'is_active' => '1',
            'bonvoice_extension' => '08448423017',
        ])
            ->assertRedirect(route('users.edit', $target))
            ->assertSessionHas('status', 'user-updated');

        $this->assertSame('08448423017', $target->fresh()->bonvoice_extension);
    }

    public function test_duplicate_bonvoice_extension_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $existing = User::factory()->create([
            'email' => 'existing-extension@test.com',
            'bonvoice_extension' => '08448423017',
        ]);
        $existing->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $target = User::factory()->create([
            'email' => 'duplicate-extension@test.com',
            'bonvoice_extension' => null,
        ]);
        $target->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($admin)->post(route('users.store'), [
            'first_name' => 'Duplicate',
            'last_name' => 'Extension',
            'email' => 'new-duplicate@test.com',
            'roles' => [RolePermissionSeeder::ROLE_AGENT],
            'password' => 'password',
            'password_confirmation' => 'password',
            'is_active' => '1',
            'bonvoice_extension' => '08448423017',
        ])->assertSessionHasErrors('bonvoice_extension');

        $this->actingAs($admin)->put(route('users.update', $target), [
            'first_name' => $target->first_name,
            'last_name' => $target->last_name,
            'email' => $target->email,
            'roles' => [RolePermissionSeeder::ROLE_AGENT],
            'is_active' => '1',
            'bonvoice_extension' => '08448423017',
        ])->assertSessionHasErrors('bonvoice_extension');
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
            'roles' => [RolePermissionSeeder::ROLE_ADMIN],
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
            'roles' => [RolePermissionSeeder::ROLE_SUPERADMIN],
            'password' => 'password',
            'password_confirmation' => 'password',
            'is_active' => '1',
        ])->assertSessionHasErrors('roles.0');
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

    public function test_cannot_remove_last_superadmin(): void
    {
        $soleSuperadmin = $this->createSuperAdmin(['email' => 'sole-super@test.com']);
        $inactiveSuperadmin = $this->createSuperAdmin([
            'email' => 'inactive-super@test.com',
            'is_active' => false,
        ]);

        $this->withoutMiddleware(EnsureUserIsActive::class);

        $this->actingAs($inactiveSuperadmin)->put(route('users.update', $soleSuperadmin), [
            'first_name' => $soleSuperadmin->first_name,
            'last_name' => $soleSuperadmin->last_name,
            'email' => $soleSuperadmin->email,
            'roles' => [RolePermissionSeeder::ROLE_ADMIN],
            'is_active' => '1',
        ])->assertSessionHasErrors('roles');

        $this->assertTrue($soleSuperadmin->fresh()->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN));
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'user.updated',
            'auditable_type' => $soleSuperadmin->getMorphClass(),
            'auditable_id' => $soleSuperadmin->id,
        ]);
    }

    public function test_cannot_deactivate_last_superadmin(): void
    {
        $soleSuperadmin = $this->createSuperAdmin(['email' => 'sole-super@test.com']);
        $inactiveSuperadmin = $this->createSuperAdmin([
            'email' => 'inactive-super@test.com',
            'is_active' => false,
        ]);

        $this->withoutMiddleware(EnsureUserIsActive::class);

        $this->actingAs($inactiveSuperadmin)->patch(route('users.status.update', $soleSuperadmin), [
            'is_active' => '0',
        ])->assertSessionHasErrors('is_active');

        $this->assertTrue($soleSuperadmin->fresh()->is_active);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'user.deactivated',
            'auditable_type' => $soleSuperadmin->getMorphClass(),
            'auditable_id' => $soleSuperadmin->id,
        ]);
    }

    public function test_cannot_delete_last_superadmin(): void
    {
        $soleSuperadmin = $this->createSuperAdmin(['email' => 'sole-super@test.com']);
        $inactiveSuperadmin = $this->createSuperAdmin([
            'email' => 'inactive-super@test.com',
            'is_active' => false,
        ]);

        $this->withoutMiddleware(EnsureUserIsActive::class);

        $this->actingAs($inactiveSuperadmin)->delete(route('users.destroy', $soleSuperadmin))
            ->assertSessionHasErrors('user');

        $this->assertNull($soleSuperadmin->fresh()->deleted_at);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'user.deleted',
            'auditable_type' => $soleSuperadmin->getMorphClass(),
            'auditable_id' => $soleSuperadmin->id,
        ]);
    }

    public function test_superadmin_cannot_remove_own_superadmin_role(): void
    {
        $superadmin = $this->createSuperAdmin(['email' => 'self-super@test.com']);
        $this->createSuperAdmin(['email' => 'other-super@test.com']);

        $this->actingAs($superadmin)->put(route('users.update', $superadmin), [
            'first_name' => $superadmin->first_name,
            'last_name' => $superadmin->last_name,
            'email' => $superadmin->email,
            'roles' => [RolePermissionSeeder::ROLE_ADMIN],
            'is_active' => '1',
        ])->assertSessionHasErrors('roles');

        $this->assertTrue($superadmin->fresh()->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN));
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'user.updated',
            'auditable_type' => $superadmin->getMorphClass(),
            'auditable_id' => $superadmin->id,
        ]);
    }

    public function test_all_seven_roles_appear_in_filter(): void
    {
        $admin = $this->createAdmin();
        $operationsRoleService = app(\App\Services\Operations\OperationsRoleService::class);

        $response = $this->actingAs($admin)->get(route('users.index'));

        foreach ($operationsRoleService->operationalRoleSlugs() as $roleSlug) {
            $response->assertSee($operationsRoleService->displayLabel($roleSlug), false);
        }
    }

    public function test_workforce_status_renders_on_user_index(): void
    {
        $admin = $this->createAdmin();
        $agent = User::factory()->create([
            'first_name' => 'Workforce',
            'last_name' => 'Agent',
            'email' => 'workforce-agent@test.com',
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available->value,
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($admin)->get(route('users.index'))
            ->assertOk()
            ->assertSee('Available', false);
    }

    public function test_superadmin_can_assign_multiple_roles(): void
    {
        $superadmin = $this->createSuperAdmin();
        $target = User::factory()->create([
            'email' => 'multi-role@test.com',
        ]);
        $target->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($superadmin)->put(route('users.update', $target), [
            'first_name' => $target->first_name,
            'last_name' => $target->last_name,
            'email' => $target->email,
            'roles' => [
                RolePermissionSeeder::ROLE_AGENT,
                RolePermissionSeeder::ROLE_HARDWARE_TEAM,
            ],
            'is_active' => '1',
        ])->assertRedirect(route('users.edit', $target));

        $target->refresh();
        $this->assertTrue($target->hasRole(RolePermissionSeeder::ROLE_AGENT));
        $this->assertTrue($target->hasRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM));
    }

    public function test_superadmin_can_remove_one_role(): void
    {
        $superadmin = $this->createSuperAdmin();
        $target = User::factory()->create([
            'email' => 'remove-role@test.com',
        ]);
        $target->syncRoles([
            RolePermissionSeeder::ROLE_AGENT,
            RolePermissionSeeder::ROLE_HARDWARE_TEAM,
        ]);

        $this->actingAs($superadmin)->put(route('users.update', $target), [
            'first_name' => $target->first_name,
            'last_name' => $target->last_name,
            'email' => $target->email,
            'roles' => [RolePermissionSeeder::ROLE_AGENT],
            'is_active' => '1',
        ])->assertRedirect(route('users.edit', $target));

        $target->refresh();
        $this->assertTrue($target->hasRole(RolePermissionSeeder::ROLE_AGENT));
        $this->assertFalse($target->hasRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM));
    }

    public function test_shipra_scenario_admin_plus_customer_coordinator_allowed(): void
    {
        $superadmin = $this->createSuperAdmin();
        $shipra = User::factory()->create([
            'first_name' => 'Shipra',
            'last_name' => 'Kumari',
            'email' => 'shipra@radiumbox.com',
        ]);
        $shipra->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($superadmin)->put(route('users.update', $shipra), [
            'first_name' => $shipra->first_name,
            'last_name' => $shipra->last_name,
            'email' => $shipra->email,
            'roles' => [
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR,
            ],
            'is_active' => '1',
        ])->assertRedirect(route('users.edit', $shipra));

        $shipra->refresh();
        $this->assertTrue($shipra->hasRole(RolePermissionSeeder::ROLE_ADMIN));
        $this->assertTrue($shipra->hasRole(RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR));
    }

    public function test_sumit_scenario_agent_plus_hardware_team_allowed(): void
    {
        $superadmin = $this->createSuperAdmin();
        $sumit = User::factory()->create([
            'first_name' => 'Sumit',
            'email' => 'sumit@radiumbox.com',
        ]);
        $sumit->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($superadmin)->put(route('users.update', $sumit), [
            'first_name' => $sumit->first_name,
            'last_name' => $sumit->last_name,
            'email' => $sumit->email,
            'roles' => [
                RolePermissionSeeder::ROLE_AGENT,
                RolePermissionSeeder::ROLE_HARDWARE_TEAM,
            ],
            'is_active' => '1',
        ])->assertRedirect(route('users.edit', $sumit));

        $sumit->refresh();
        $this->assertTrue($sumit->hasRole(RolePermissionSeeder::ROLE_AGENT));
        $this->assertTrue($sumit->hasRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM));
    }

    public function test_audit_stores_roles_array_on_update(): void
    {
        $superadmin = $this->createSuperAdmin();
        $target = User::factory()->create([
            'email' => 'audit-roles@test.com',
        ]);
        $target->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($superadmin)->put(route('users.update', $target), [
            'first_name' => $target->first_name,
            'last_name' => $target->last_name,
            'email' => $target->email,
            'roles' => [
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR,
            ],
            'is_active' => '1',
        ])->assertRedirect(route('users.edit', $target));

        $audit = AuditLog::query()
            ->where('event', 'user.updated')
            ->where('auditable_type', $target->getMorphClass())
            ->where('auditable_id', $target->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame(['agent'], $audit->old_values['roles']);
        $this->assertSame([
            'admin',
            'customer_coordinator',
        ], $audit->new_values['roles']);
    }
}
