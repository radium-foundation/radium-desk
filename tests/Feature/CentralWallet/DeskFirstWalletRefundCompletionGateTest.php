<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\CashfreeCentralCustomerBinder;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Refunds\DeskFirstWalletRefundCompletionGate;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeskFirstWalletRefundCompletionGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['central_wallet.wallet_refund.desk_first_enabled' => true]);
    }

    public function test_rejects_completion_when_desk_first_enabled_but_execution_lacks_ledger_evidence(): void
    {
        $refund = $this->walletRefund('REF-GATE-1', '50.00');

        $gate = app(DeskFirstWalletRefundCompletionGate::class);

        $this->expectException(ValidationException::class);
        $gate->assertMayComplete($refund, [
            'provider' => 'rdservice_in_wallet',
            'reference_number' => '9999',
            'transaction_id' => '9999',
            'remarks' => 'forged',
            'metadata' => [],
        ]);
    }

    public function test_accepts_valid_desk_first_execution_binding(): void
    {
        [$refund, $entry] = $this->refundWithPostedCredit('REF-GATE-2', '75.00');

        $gate = app(DeskFirstWalletRefundCompletionGate::class);
        $gate->assertMayComplete($refund, [
            'provider' => 'desk_first_central_wallet',
            'reference_number' => 'CW:'.$entry->id,
            'transaction_id' => (string) $entry->id,
            'remarks' => 'ok',
            'metadata' => [
                'desk_first' => true,
                'central_wallet_ledger_entry_id' => $entry->id,
                'spoke_audit' => ['provider' => 'rdservice_in_wallet_audit'],
            ],
        ]);

        $this->assertTrue(true);
    }

    public function test_rejects_wrong_ledger_amount(): void
    {
        [$refund, $entry] = $this->refundWithPostedCredit('REF-GATE-3', '80.00');
        $entry->update(['amount' => '79.00']);

        $gate = app(DeskFirstWalletRefundCompletionGate::class);

        $this->expectException(ValidationException::class);
        $gate->assertMayComplete($refund, $this->validExecution($entry));
    }

    public function test_rejects_legacy_spoke_when_only_desk_authoritative_enabled(): void
    {
        config([
            'central_wallet.wallet_refund.desk_first_enabled' => false,
            'central_wallet.wallet_refund.desk_authoritative_enabled' => true,
        ]);

        $refund = $this->walletRefund('REF-GATE-AUTH', '50.00');
        $gate = app(DeskFirstWalletRefundCompletionGate::class);

        $this->expectException(ValidationException::class);
        $gate->assertMayComplete($refund, [
            'provider' => 'radiumbox_wallet',
            'reference_number' => 'RD2586',
            'transaction_id' => '2586',
            'remarks' => 'legacy spoke only',
            'metadata' => [],
        ]);
    }

    public function test_gate_is_no_op_when_desk_first_disabled(): void
    {
        config([
            'central_wallet.wallet_refund.desk_first_enabled' => false,
            'central_wallet.wallet_refund.desk_authoritative_enabled' => false,
        ]);
        $refund = $this->walletRefund('REF-GATE-4', '10.00');
        $gate = app(DeskFirstWalletRefundCompletionGate::class);
        $gate->assertMayComplete($refund, [
            'provider' => 'rdservice_in_wallet',
            'reference_number' => '2667',
            'transaction_id' => '2667',
            'remarks' => 'legacy',
        ]);

        $this->assertTrue(true);
    }

    /**
     * @return array{0: RefundRequest, 1: CentralWalletLedgerEntry}
     */
    private function refundWithPostedCredit(string $reference, string $amount): array
    {
        $refund = $this->walletRefund($reference, $amount);
        $customerId = (string) $refund->order?->customer_id;
        $resolved = app(CashfreeCentralCustomerBinder::class)->resolveCustomerWallet($customerId);
        $cwid = (string) ($resolved['central_wallet_id'] ?? '');

        $entry = CentralWalletLedgerEntry::query()->create([
            'central_wallet_id' => $cwid,
            'entry_type' => LedgerEntryType::Credit,
            'amount' => $amount,
            'currency' => 'INR',
            'status' => 'posted',
            'source_system' => 'rdservice.in',
            'source_reference' => 'refund_requests:'.$refund->id,
            'correlation_id' => (string) Str::uuid(),
            'business_reference' => $reference,
            'posted_at' => now(),
        ]);

        return [$refund, $entry];
    }

    /**
     * @return array<string, mixed>
     */
    private function validExecution(CentralWalletLedgerEntry $entry): array
    {
        return [
            'provider' => 'desk_first_central_wallet',
            'reference_number' => 'CW:'.$entry->id,
            'transaction_id' => (string) $entry->id,
            'remarks' => 'ok',
            'metadata' => [
                'desk_first' => true,
                'central_wallet_ledger_entry_id' => $entry->id,
                'spoke_audit' => ['provider' => 'rdservice_in_wallet_audit'],
            ],
        ];
    }

    private function walletRefund(string $reference, string $amount): RefundRequest
    {
        $customerId = (string) Str::uuid();
        $cwid = (string) Str::uuid();
        CentralWallet::query()->create(['id' => $cwid, 'status' => 'active']);
        CentralCustomer::query()->create([
            'id' => $customerId,
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD90501',
            'customer_id' => $customerId,
            'serial_number' => 'SN-RD90501',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'payment_amount' => $amount,
            'customer_email' => 'gate-test@example.com',
        ]);

        return RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => $reference,
            'amount' => $amount,
            'refund_amount' => $amount,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'status' => RefundStatus::PendingExecution,
            'reason' => 'test',
            'requester_remarks' => 'test',
            'customer_preferred_method' => 'wallet',
            'requested_by' => User::factory()->create()->id,
        ]);
    }
}
