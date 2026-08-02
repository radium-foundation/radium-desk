<?php

namespace Tests\Feature\Finance;

use App\Enums\FinanceJournalSourceType;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Models\FinanceCashAccount;
use App\Models\FinanceExpense;
use App\Models\FinanceExpenseCategory;
use App\Models\FinanceJournal;
use App\Models\FinancePaymentMethod;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Finance\OrderPaymentJournalService;
use App\Services\Finance\RefundJournalService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DisablesRequestForgeryProtection;
use Tests\TestCase;

class FinanceLedgerIntegrationTest extends TestCase
{
    use DisablesRequestForgeryProtection;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRequestForgeryProtection();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    public function test_posting_expense_creates_balanced_journal(): void
    {
        $category = FinanceExpenseCategory::query()->where('name', 'Office')->firstOrFail();
        $method = FinancePaymentMethod::query()->where('name', 'Cash')->firstOrFail();
        $cash = FinanceCashAccount::query()->where('name', 'Main Cash Drawer')->firstOrFail();

        $this->assertNotNull($category->default_gl_account_id);
        $this->assertNotNull($cash->gl_account_id);

        $response = $this->actingAs($this->admin)->post(route('finance.expenses.store'), [
            'expense_date' => now()->toDateString(),
            'expense_category_id' => $category->id,
            'amount' => '120.00',
            'payment_method_id' => $method->id,
            'account_type' => 'cash',
            'cash_account_id' => $cash->id,
            'description' => 'Office stationery',
        ]);

        $expense = FinanceExpense::query()->firstOrFail();
        $response->assertRedirect(route('finance.expenses.show', $expense));

        $this->actingAs($this->admin)
            ->post(route('finance.expenses.post', $expense))
            ->assertRedirect(route('finance.expenses.show', $expense));

        $expense->refresh();
        $this->assertNotNull($expense->journal_id);

        $journal = FinanceJournal::query()->findOrFail($expense->journal_id);
        $this->assertSame(FinanceJournalSourceType::Expense, $journal->source_type);
        $this->assertSame('120.00', $journal->totalDebits());
        $this->assertSame('120.00', $journal->totalCredits());
        $this->assertSame('expense:'.$expense->id, $journal->idempotency_key);
    }

    public function test_order_payment_journal_is_idempotent(): void
    {
        $order = Order::query()->create([
            'order_id' => 'RD-LEDGER-'.uniqid(),
            'serial_number' => 'SN-LEDGER-'.uniqid(),
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => OrderStatus::Active,
            'payment_amount' => 499.00,
            'payment_date' => now(),
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $service = app(OrderPaymentJournalService::class);
        $first = $service->postForOrder($order, $this->admin);
        $second = $service->postForOrder($order, $this->admin);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertSame(1, FinanceJournal::query()->where('source_type', FinanceJournalSourceType::OrderPayment)->count());
        $this->assertSame('499.00', $first->totalDebits());
    }

    public function test_refund_journal_posts_from_completed_refund(): void
    {
        $order = Order::query()->create([
            'order_id' => 'RD-REF-'.uniqid(),
            'serial_number' => 'SN-REF-'.uniqid(),
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => OrderStatus::Active,
            'payment_amount' => 1000.00,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-'.random_int(100000, 999999),
            'amount' => 200,
            'refund_amount' => 200,
            'reason' => 'Ledger integration refund fixture.',
            'status' => RefundStatus::Completed,
            'requested_by' => $this->admin->id,
            'executed_by' => $this->admin->id,
            'executed_at' => now(),
        ]);

        $service = app(RefundJournalService::class);
        $journal = $service->postForRefund($refund, $this->admin);

        $this->assertNotNull($journal);
        $this->assertSame(FinanceJournalSourceType::Refund, $journal->source_type);
        $this->assertSame('200.00', $journal->totalDebits());
        $this->assertSame('refund:'.$refund->id, $journal->idempotency_key);
    }

    public function test_cash_ledger_page_renders_with_balance(): void
    {
        $this->actingAs($this->admin)
            ->get(route('finance.cash.index'))
            ->assertOk()
            ->assertSee('Cash Ledger');
    }

    public function test_journal_audit_page_is_reachable(): void
    {
        $this->actingAs($this->admin)
            ->get(route('finance.settings.journals'))
            ->assertOk()
            ->assertSee('Journal Audit');
    }
}
