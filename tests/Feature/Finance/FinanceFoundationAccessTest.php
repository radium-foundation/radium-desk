<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceFoundationAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_can_open_finance_placeholder_pages(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($user)
            ->get(route('finance.dashboard'))
            ->assertOk()
            ->assertSee('Cash in Hand')
            ->assertSee('Pending Approvals');

        $this->actingAs($user)
            ->get(route('finance.payments.index'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('finance.settings.cash-accounts'))
            ->assertOk()
            ->assertSee('Cash Accounts');
    }

    public function test_agent_cannot_access_finance_hub(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($user)
            ->get(route('finance.dashboard'))
            ->assertForbidden();
    }

    public function test_finance_view_permission_expands_module_view_grants(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_FINANCE_VIEW));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_FINANCE_DASHBOARD_VIEW));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_FINANCE_PAYMENTS_VIEW));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_FINANCE_SETTINGS_VIEW));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_FINANCE_PARTIES_VIEW));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_FINANCE_PARTIES_MANAGE));
        $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_FINANCE_PARTIES_BANK_VIEW));
    }

    public function test_admin_can_open_party_master(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($user)
            ->get(route('finance.parties.index'))
            ->assertOk()
            ->assertSee('Parties');
    }
}
