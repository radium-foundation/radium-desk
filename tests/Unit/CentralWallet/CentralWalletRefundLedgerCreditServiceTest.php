<?php

namespace Tests\Unit\CentralWallet;

use App\CentralWallet\Application\CentralWalletRefundLedgerCreditService;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralWalletRefundLedgerCreditServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central_wallet.wallet_refund.desk_first_enabled' => true]);
    }

    public function test_credits_once_and_replays_idempotently(): void
    {
        $refund = $this->refundForOrder('RD90010', 'REF-IDEM-1', '75.00');
        $service = app(CentralWalletRefundLedgerCreditService::class);

        $first = $service->creditForRefund($refund, (string) Str::uuid());
        $second = $service->creditForRefund($refund, (string) Str::uuid());

        $this->assertFalse($first['idempotent_replay']);
        $this->assertTrue($second['idempotent_replay']);
        $this->assertSame($first['ledger_entry_id'], $second['ledger_entry_id']);
        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', 'REF-IDEM-1')->count());
    }

    private function refundForOrder(string $orderId, string $reference, string $amount): RefundRequest
    {
        $user = User::factory()->create();
        $customerId = (string) Str::uuid();
        $cwid = (string) Str::uuid();
        CentralWallet::query()->create(['id' => $cwid, 'status' => 'active']);
        CentralCustomer::query()->create([
            'id' => $customerId,
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'serial_number' => 'SN',
            'product_name' => 'Device',
            'device_model' => 'X',
            'status' => 'active',
            'payment_amount' => $amount,
            'customer_email' => 'unit@example.com',
            'created_by' => $user->id,
        ]);

        return RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => $reference,
            'amount' => $amount,
            'refund_amount' => $amount,
            'reason' => 'unit test',
            'status' => RefundStatus::PendingExecution,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $user->id,
        ]);
    }
}
