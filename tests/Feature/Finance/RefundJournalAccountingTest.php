<?php

namespace Tests\Feature\Finance;

use App\Enums\ApprovedRefundMethod;
use App\Enums\FinanceAccountType;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Events\Finance\RefundCompleted;
use App\Models\FinanceAccount;
use App\Models\FinanceJournal;
use App\Models\FinanceJournalLine;
use App\Models\FinanceSetting;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Finance\FinanceSettingsService;
use App\Services\Finance\RefundJournalService;
use Database\Seeders\FinanceChartOfAccountsSeeder;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RefundJournalAccountingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    public function test_wallet_full_refund_credits_wallet_liability_not_bank_clearing(): void
    {
        $journal = $this->postRefundJournal(
            method: ApprovedRefundMethod::Wallet,
            amount: 499.00,
            executionReference: 'CW:72',
        );

        $this->assertJournalUsesAccounts(
            journal: $journal,
            debitCode: FinanceChartOfAccountsSeeder::CODE_REFUND_EXPENSE,
            creditCode: FinanceChartOfAccountsSeeder::CODE_WALLET_LIABILITY,
            amount: '499.00',
        );
        $this->assertStringContainsString('CW:72', (string) $journal->memo);
    }

    public function test_wallet_partial_refund_still_credits_wallet_liability(): void
    {
        $journal = $this->postRefundJournal(
            method: ApprovedRefundMethod::Wallet,
            amount: 49.00,
            paymentAmount: 3049.00,
        );

        $this->assertJournalUsesAccounts(
            journal: $journal,
            debitCode: FinanceChartOfAccountsSeeder::CODE_REFUND_EXPENSE,
            creditCode: FinanceChartOfAccountsSeeder::CODE_WALLET_LIABILITY,
            amount: '49.00',
        );
    }

    public function test_opm_full_refund_credits_bank_clearing_not_wallet_liability(): void
    {
        foreach ([ApprovedRefundMethod::Cashfree, ApprovedRefundMethod::BankTransfer, ApprovedRefundMethod::Upi, ApprovedRefundMethod::Other] as $method) {
            $journal = $this->postRefundJournal(method: $method, amount: 381.00);

            $this->assertJournalUsesAccounts(
                journal: $journal,
                debitCode: FinanceChartOfAccountsSeeder::CODE_REFUND_EXPENSE,
                creditCode: FinanceChartOfAccountsSeeder::CODE_BANK_CLEARING,
                amount: '381.00',
            );
        }
    }

    public function test_opm_partial_refund_credits_bank_clearing(): void
    {
        $journal = $this->postRefundJournal(
            method: ApprovedRefundMethod::Cashfree,
            amount: 381.00,
            paymentAmount: 499.00,
        );

        $this->assertJournalUsesAccounts(
            journal: $journal,
            debitCode: FinanceChartOfAccountsSeeder::CODE_REFUND_EXPENSE,
            creditCode: FinanceChartOfAccountsSeeder::CODE_BANK_CLEARING,
            amount: '381.00',
        );
    }

    public function test_refund_journal_is_idempotent(): void
    {
        $refund = $this->makeRefund(
            method: ApprovedRefundMethod::Wallet,
            amount: 200.00,
        );

        $service = app(RefundJournalService::class);
        $first = $service->postForRefund($refund, $this->admin);
        $second = $service->postForRefund($refund->fresh(), $this->admin);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertSame(1, FinanceJournal::query()->where('source_type', 'refund')->where('source_id', $refund->id)->count());
    }

    public function test_refund_completed_listener_posts_single_wallet_journal(): void
    {
        $refund = $this->makeRefund(method: ApprovedRefundMethod::Wallet, amount: 150.00);

        RefundCompleted::dispatch($refund, $this->admin);
        RefundCompleted::dispatch($refund->fresh(), $this->admin);

        $journal = FinanceJournal::query()->where('source_type', 'refund')->where('source_id', $refund->id)->firstOrFail();
        $this->assertSame('150.00', $journal->totalDebits());
        $this->assertSame(1, FinanceJournal::query()->where('source_type', 'refund')->where('source_id', $refund->id)->count());
    }

    public function test_wallet_refund_fails_closed_when_wallet_liability_missing(): void
    {
        FinanceSetting::putValue(FinanceSettingsService::KEY_DEFAULT_WALLET_LIABILITY, null);

        $refund = $this->makeRefund(method: ApprovedRefundMethod::Wallet, amount: 100.00);

        $this->expectException(ValidationException::class);
        app(RefundJournalService::class)->postForRefund($refund, $this->admin);
    }

    public function test_wallet_refund_fails_closed_when_wallet_liability_is_bank_clearing(): void
    {
        FinanceSetting::putValue(
            FinanceSettingsService::KEY_DEFAULT_WALLET_LIABILITY,
            FinanceChartOfAccountsSeeder::CODE_BANK_CLEARING,
        );

        $refund = $this->makeRefund(method: ApprovedRefundMethod::Wallet, amount: 100.00);

        $this->expectException(ValidationException::class);
        app(RefundJournalService::class)->postForRefund($refund, $this->admin);
    }

    public function test_wallet_refund_fails_closed_when_wallet_liability_is_not_liability_type(): void
    {
        FinanceAccount::query()->updateOrCreate(
            ['code' => '2199'],
            [
                'name' => 'Invalid Wallet Asset',
                'type' => FinanceAccountType::Asset,
                'is_system' => false,
                'is_active' => true,
            ],
        );
        FinanceSetting::putValue(FinanceSettingsService::KEY_DEFAULT_WALLET_LIABILITY, '2199');

        $refund = $this->makeRefund(method: ApprovedRefundMethod::Wallet, amount: 100.00);

        $this->expectException(ValidationException::class);
        app(RefundJournalService::class)->postForRefund($refund, $this->admin);
    }

    public function test_refund_without_approved_method_fails_closed(): void
    {
        $order = Order::query()->create([
            'order_id' => 'RD-NOMETHOD-'.uniqid(),
            'serial_number' => 'SN-NOMETHOD',
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => OrderStatus::Active,
            'payment_amount' => 100.00,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-NOMETHOD-'.uniqid(),
            'amount' => 100,
            'refund_amount' => 100,
            'reason' => 'Fixture without method.',
            'status' => RefundStatus::Completed,
            'requested_by' => $this->admin->id,
            'executed_by' => $this->admin->id,
            'executed_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        app(RefundJournalService::class)->postForRefund($refund, $this->admin);
    }

    private function postRefundJournal(
        ApprovedRefundMethod $method,
        float $amount,
        ?float $paymentAmount = null,
        ?string $executionReference = null,
    ): FinanceJournal {
        $refund = $this->makeRefund(
            method: $method,
            amount: $amount,
            paymentAmount: $paymentAmount ?? $amount,
            executionReference: $executionReference,
        );

        $journal = app(RefundJournalService::class)->postForRefund($refund, $this->admin);
        $this->assertNotNull($journal);

        return $journal->load('lines.account');
    }

    private function makeRefund(
        ApprovedRefundMethod $method,
        float $amount,
        ?float $paymentAmount = null,
        ?string $executionReference = null,
    ): RefundRequest {
        $order = Order::query()->create([
            'order_id' => 'RD-JRN-'.uniqid(),
            'serial_number' => 'SN-JRN-'.uniqid(),
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => OrderStatus::Active,
            'payment_amount' => $paymentAmount ?? $amount,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        return RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-JRN-'.uniqid(),
            'amount' => $amount,
            'refund_amount' => $amount,
            'reason' => 'Journal accounting fixture.',
            'status' => RefundStatus::Completed,
            'approved_refund_method' => $method,
            'execution_reference_no' => $executionReference,
            'requested_by' => $this->admin->id,
            'executed_by' => $this->admin->id,
            'executed_at' => now(),
        ]);
    }

    private function assertJournalUsesAccounts(
        FinanceJournal $journal,
        string $debitCode,
        string $creditCode,
        string $amount,
    ): void {
        $journal->loadMissing('lines.account');

        $debitLine = $journal->lines->first(fn (FinanceJournalLine $line) => (float) $line->debit > 0);
        $creditLine = $journal->lines->first(fn (FinanceJournalLine $line) => (float) $line->credit > 0);

        $this->assertNotNull($debitLine);
        $this->assertNotNull($creditLine);
        $this->assertSame($debitCode, $debitLine->account?->code);
        $this->assertSame($creditCode, $creditLine->account?->code);
        $this->assertSame($amount, $journal->totalDebits());
        $this->assertSame($amount, $journal->totalCredits());
    }
}
