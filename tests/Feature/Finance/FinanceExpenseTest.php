<?php

namespace Tests\Feature\Finance;

use App\Enums\FinanceExpenseStatus;
use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceExpense;
use App\Models\FinanceExpenseCategory;
use App\Models\FinancePaymentMethod;
use App\Models\User;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\DisablesRequestForgeryProtection;
use Tests\TestCase;

class FinanceExpenseTest extends TestCase
{
    use DisablesRequestForgeryProtection;
    use RefreshDatabase;

    private User $admin;

    private FinanceExpenseCategory $category;

    private FinancePaymentMethod $paymentMethod;

    private FinanceCashAccount $cashAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRequestForgeryProtection();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->category = FinanceExpenseCategory::query()->where('name', 'Office')->firstOrFail();
        $this->paymentMethod = FinancePaymentMethod::query()->where('name', 'Cash')->firstOrFail();
        $this->cashAccount = FinanceCashAccount::query()->where('name', 'Main Cash Drawer')->firstOrFail();
    }

    public function test_admin_can_create_draft_expense_from_cash_account(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('finance.expenses.store'), $this->validPayload());

        $expense = FinanceExpense::query()->firstOrFail();

        $response->assertRedirect(route('finance.expenses.show', $expense));
        $this->assertSame(FinanceExpenseStatus::Draft, $expense->status);
        $this->assertStringStartsWith('EXP-'.now()->format('Y').'-', $expense->expense_no);
        $this->assertSame($this->cashAccount->id, $expense->cash_account_id);
        $this->assertNull($expense->bank_account_id);
        $this->assertSame($this->admin->id, $expense->created_by);
    }

    public function test_admin_can_edit_and_post_draft_expense(): void
    {
        $expense = $this->createDraftExpense();

        $this->actingAs($this->admin)
            ->put(route('finance.expenses.update', $expense), array_merge($this->validPayload(), [
                'amount' => '250.50',
                'description' => 'Updated office supplies',
            ]))
            ->assertRedirect(route('finance.expenses.show', $expense));

        $this->assertSame('250.50', $expense->fresh()->amount);
        $this->assertSame('Updated office supplies', $expense->fresh()->description);

        $this->actingAs($this->admin)
            ->post(route('finance.expenses.post', $expense))
            ->assertRedirect(route('finance.expenses.show', $expense))
            ->assertSessionHas('status', 'finance-expense-posted');

        $expense->refresh();
        $this->assertTrue($expense->isPosted());
        $this->assertSame($this->admin->id, $expense->posted_by);
        $this->assertNotNull($expense->posted_at);
    }

    public function test_posted_expense_cannot_be_edited(): void
    {
        $expense = $this->createDraftExpense();
        $expense->update([
            'status' => FinanceExpenseStatus::Posted,
            'posted_at' => now(),
            'posted_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('finance.expenses.edit', $expense))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->put(route('finance.expenses.update', $expense), $this->validPayload())
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->post(route('finance.expenses.post', $expense))
            ->assertForbidden();
    }

    public function test_expense_requires_exactly_one_funding_account(): void
    {
        $bank = FinanceBankAccount::query()->create([
            'bank_name' => 'HDFC',
            'account_name' => 'Ops',
            'last_four' => '4321',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->from(route('finance.expenses.create'))
            ->post(route('finance.expenses.store'), array_merge($this->validPayload(), [
                'account_type' => 'cash',
                'cash_account_id' => null,
                'bank_account_id' => null,
            ]))
            ->assertSessionHasErrors();

        $this->actingAs($this->admin)
            ->post(route('finance.expenses.store'), array_merge($this->validPayload(), [
                'account_type' => 'cash',
                'cash_account_id' => $this->cashAccount->id,
                'bank_account_id' => $bank->id,
            ]))
            ->assertRedirect();

        $expense = FinanceExpense::query()->latest('id')->firstOrFail();
        $this->assertSame($this->cashAccount->id, $expense->cash_account_id);
        $this->assertNull($expense->bank_account_id);

        $this->actingAs($this->admin)
            ->post(route('finance.expenses.store'), array_merge($this->validPayload(), [
                'account_type' => 'bank',
                'cash_account_id' => $this->cashAccount->id,
                'bank_account_id' => $bank->id,
                'description' => 'Bank-funded expense',
            ]))
            ->assertRedirect();

        $bankExpense = FinanceExpense::query()->where('description', 'Bank-funded expense')->firstOrFail();
        $this->assertNull($bankExpense->cash_account_id);
        $this->assertSame($bank->id, $bankExpense->bank_account_id);
    }

    public function test_expense_list_supports_search_and_status_filter(): void
    {
        $match = $this->createDraftExpense(['description' => 'Courier to customer']);
        $this->createDraftExpense(['description' => 'Office snacks']);

        $this->actingAs($this->admin)
            ->get(route('finance.expenses.index', [
                'q' => 'Courier',
                'status' => FinanceExpenseStatus::Draft->value,
            ]))
            ->assertOk()
            ->assertSee($match->expense_no)
            ->assertDontSee('Office snacks');
    }

    public function test_agent_cannot_access_expenses(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('finance.expenses.index'))
            ->assertForbidden();
    }

    public function test_receipt_can_be_uploaded_on_create(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post(route('finance.expenses.store'), array_merge($this->validPayload(), [
                'receipt' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
            ]))
            ->assertRedirect();

        $expense = FinanceExpense::query()->firstOrFail();
        $this->assertNotNull($expense->receipt_path);
        Storage::disk('public')->assertExists($expense->receipt_path);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'expense_date' => now()->toDateString(),
            'expense_category_id' => $this->category->id,
            'amount' => '100.00',
            'payment_method_id' => $this->paymentMethod->id,
            'account_type' => 'cash',
            'cash_account_id' => $this->cashAccount->id,
            'bank_account_id' => null,
            'description' => 'Office supplies',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createDraftExpense(array $overrides = []): FinanceExpense
    {
        return FinanceExpense::query()->create(array_merge([
            'expense_no' => 'EXP-'.now()->format('Y').'-'.str_pad((string) (FinanceExpense::query()->count() + 1), 6, '0', STR_PAD_LEFT),
            'expense_date' => now()->toDateString(),
            'expense_category_id' => $this->category->id,
            'amount' => 100,
            'payment_method_id' => $this->paymentMethod->id,
            'cash_account_id' => $this->cashAccount->id,
            'bank_account_id' => null,
            'description' => 'Office supplies',
            'status' => FinanceExpenseStatus::Draft,
            'created_by' => $this->admin->id,
        ], $overrides));
    }
}
