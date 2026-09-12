<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FinanceFoundationAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    public function test_finance_legacy_cash_named_route_is_registered(): void
    {
        $this->assertTrue(
            Route::has('finance.legacy-cash.index'),
            'finance.legacy-cash.index must remain registered; the finance workspace nav calls route() unconditionally.',
        );
    }

    public function test_purchasing_purchase_orders_named_route_is_registered(): void
    {
        $this->assertTrue(
            Route::has('purchasing.purchase-orders.index'),
            'purchasing.purchase-orders.index must remain registered; NavigationContextResolver calls route() for purchase.view users.',
        );
    }

    public function test_pos_counter_route_dependencies_are_registered(): void
    {
        $this->assertTrue(Route::has('pos.serials.match'));
        $this->assertTrue(Route::has('pos.customers.search'));
        $this->assertTrue(Route::has('pos.customers.show'));
    }

    public function test_pos_sale_statutory_invoice_routes_are_registered(): void
    {
        $this->assertTrue(Route::has('pos.sales.statutory.pdf'));
        $this->assertTrue(Route::has('pos.sales.statutory.download'));
        $this->assertTrue(Route::has('pos.sales.statutory.email'));
    }
}
