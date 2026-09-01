<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Models\User;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatutoryInvoiceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
    }

    public function test_accountant_can_view_and_export_invoices_but_not_operate_pos_or_inventory(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ACCOUNTANT);

        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_FINANCE_ACCOUNTANT_ACCESS));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_FINANCE_INVOICES_VIEW));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_FINANCE_GST_REPORTS));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_FINANCE_SALES_REPORTS));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_FINANCE_REPORTS_EXPORT));
        $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_FINANCE_VIEW));
        $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_FINANCE_SETTINGS_VIEW));
        $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_POS_VIEW));
        $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_POS_SELL));
        $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_INVENTORY_VIEW));
        $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_ADJUST));
        $this->assertFalse($user->can('users.manage'));
        $this->assertFalse($user->can('system-settings.manage'));

        $this->actingAs($user)->get(route('finance.invoices.index'))->assertOk();
        $this->actingAs($user)->get(route('finance.reports.index'))->assertOk();
        $this->actingAs($user)->get(route('finance.invoices.export'))->assertOk();
        $this->actingAs($user)->get('/finance')->assertRedirect(route('finance.invoices.index'));
        $this->actingAs($user)->get(route('finance.dashboard'))->assertForbidden();
        $this->actingAs($user)->get(route('finance.settings.cash-accounts'))->assertForbidden();
        $this->actingAs($user)->get(route('pos.counter.create'))->assertForbidden();
        $this->actingAs($user)->get(route('inventory.stock.index'))->assertForbidden();
        $this->actingAs($user)->get(route('inventory.adjustments.create'))->assertForbidden();
        $this->actingAs($user)->post(route('finance.invoices.index'))->assertStatus(405);
        $this->actingAs($user)->delete(route('finance.invoices.show', ['invoice' => 1]))->assertStatus(405);
    }

    public function test_hardware_pos_user_cannot_view_statutory_invoices_or_numbering_reports(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_POS_SELL));
        $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_FINANCE_INVOICES_VIEW));
        $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_FINANCE_ACCOUNTANT_ACCESS));

        $this->actingAs($user)->get(route('finance.invoices.index'))->assertForbidden();
        $this->actingAs($user)->get(route('finance.reports.index'))->assertForbidden();
        $this->actingAs($user)->get(route('finance.invoices.export'))->assertForbidden();
        $this->actingAs($user)->get(route('pos.counter.create'))->assertOk();
    }

    public function test_permission_seeder_keeps_accountant_grants_when_run_twice(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ACCOUNTANT);

        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_FINANCE_INVOICES_VIEW));
        $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_POS_SELL));
    }
}
