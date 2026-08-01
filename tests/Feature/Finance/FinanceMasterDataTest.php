<?php

namespace Tests\Feature\Finance;

use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceExpenseCategory;
use App\Models\FinancePaymentMethod;
use App\Models\User;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DisablesRequestForgeryProtection;
use Tests\TestCase;

class FinanceMasterDataTest extends TestCase
{
    use DisablesRequestForgeryProtection;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRequestForgeryProtection();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
    }

    public function test_seeder_creates_default_master_rows(): void
    {
        $this->assertDatabaseHas('finance_payment_methods', ['name' => 'Cash', 'is_active' => true]);
        $this->assertDatabaseHas('finance_payment_methods', ['name' => 'Cashfree', 'is_active' => true]);
        $this->assertDatabaseHas('finance_expense_categories', ['name' => 'Courier', 'is_active' => true]);
        $this->assertDatabaseHas('finance_expense_categories', ['name' => 'Salary', 'is_active' => true]);
        $this->assertDatabaseHas('finance_cash_accounts', ['name' => 'Main Cash Drawer', 'is_active' => true]);
        $this->assertSame(5, FinancePaymentMethod::query()->count());
        $this->assertSame(6, FinanceExpenseCategory::query()->count());
        $this->assertSame(1, FinanceCashAccount::query()->count());
        $this->assertSame(0, FinanceBankAccount::query()->count());
    }

    public function test_admin_can_manage_payment_methods(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($user)
            ->get(route('finance.settings.payment-methods'))
            ->assertOk()
            ->assertSee('Cash')
            ->assertSee('UPI');

        $this->actingAs($user)
            ->post(route('finance.settings.payment-methods.store'), ['name' => 'Cheque'])
            ->assertRedirect(route('finance.settings.payment-methods'))
            ->assertSessionHas('status', 'finance-payment-method-created');

        $method = FinancePaymentMethod::query()->where('name', 'Cheque')->firstOrFail();

        $this->actingAs($user)
            ->put(route('finance.settings.payment-methods.update', $method), ['name' => 'Cheque / DD'])
            ->assertRedirect(route('finance.settings.payment-methods'));

        $this->actingAs($user)
            ->patch(route('finance.settings.payment-methods.toggle', $method))
            ->assertRedirect(route('finance.settings.payment-methods'));

        $this->assertFalse($method->fresh()->is_active);
    }

    public function test_admin_can_manage_bank_accounts(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($user)
            ->post(route('finance.settings.bank-accounts.store'), [
                'bank_name' => 'HDFC Bank',
                'account_name' => 'Radium Operating',
                'last_four' => '1234',
            ])
            ->assertRedirect(route('finance.settings.bank-accounts'))
            ->assertSessionHas('status', 'finance-bank-account-created');

        $account = FinanceBankAccount::query()->firstOrFail();

        $this->assertSame('1234', $account->last_four);

        $this->actingAs($user)
            ->put(route('finance.settings.bank-accounts.update', $account), [
                'bank_name' => 'HDFC Bank',
                'account_name' => 'Radium Ops',
                'last_four' => '5678',
            ])
            ->assertRedirect(route('finance.settings.bank-accounts'));

        $this->assertSame('Radium Ops', $account->fresh()->account_name);
    }

    public function test_agent_cannot_mutate_finance_settings(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($user)
            ->post(route('finance.settings.cash-accounts.store'), ['name' => 'Petty Cash'])
            ->assertForbidden();
    }

    public function test_bank_account_requires_four_digit_suffix(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($user)
            ->post(route('finance.settings.bank-accounts.store'), [
                'bank_name' => 'ICICI',
                'account_name' => 'Ops',
                'last_four' => '12',
            ])
            ->assertSessionHasErrors('last_four');
    }
}
