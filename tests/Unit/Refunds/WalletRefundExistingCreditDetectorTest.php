<?php

namespace Tests\Unit\Refunds;

use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Refunds\WalletRefundExistingCreditDetection;
use App\Services\Refunds\WalletRefundExistingCreditDetector;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CentralWalletLedgerTestHelper;
use Tests\TestCase;

class WalletRefundExistingCreditDetectorTest extends TestCase
{
    use CentralWalletLedgerTestHelper;
    use RefreshDatabase;

    private WalletRefundExistingCreditDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config([
            'rdservice_in.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.rdservice_in.enabled' => true,
            'order_lookup.spokes.rdservice_in.base_url' => 'https://rdservice.in.test',
            'order_lookup.spokes.rdservice_in.token' => 'token',
        ]);

        $this->detector = app(WalletRefundExistingCreditDetector::class);
    }

    public function test_detects_matching_posted_credit(): void
    {
        $refund = $this->makeRefund('RDUNIT1', 'REF-UNIT-1', '200.00');
        $cwid = (string) Str::uuid();
        $this->seedPostedCreditLedgerEntry($cwid, 'REF-UNIT-1', 'RDUNIT1', '200.00');

        $result = $this->detector->detect($refund);

        $this->assertTrue($result->isMatched());
        $this->assertSame('CW:'.$result->ledgerEntry()?->id, $result->walletReference());
    }

    public function test_returns_not_found_when_no_ledger_credit_exists(): void
    {
        $refund = $this->makeRefund('RDUNIT2', 'REF-UNIT-2', '100.00');

        $this->assertTrue($this->detector->detect($refund)->isNotFound());
    }

    public function test_radiumbox_refunds_do_not_use_central_wallet_detector(): void
    {
        $refund = $this->makeRefund('RB100', 'REF-UNIT-BOX', '100.00');

        $this->assertFalse($this->detector->supports($refund));
        $this->assertTrue($this->detector->detect($refund)->isUnsupported());
    }

    public function test_currency_mismatch_is_blocking(): void
    {
        $refund = $this->makeRefund('RDUNIT3', 'REF-UNIT-3', '100.00');
        $cwid = (string) Str::uuid();
        $this->seedCentralWallet($cwid);

        \DB::table('central_wallet_ledger_entries')->insert([
            'central_wallet_id' => $cwid,
            'entry_type' => 'credit',
            'amount' => '100.00',
            'currency' => 'USD',
            'status' => 'posted',
            'source_system' => 'rdservice.in',
            'source_reference' => 'desk_refund:RDUNIT3',
            'correlation_id' => (string) Str::uuid(),
            'business_reference' => 'REF-UNIT-3',
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->detector->detect($refund);
        $this->assertSame(WalletRefundExistingCreditDetection::STATUS_CURRENCY_MISMATCH, $result->status());
        $this->assertTrue($result->isBlocking());
    }

    private function makeRefund(string $orderId, string $reference, string $amount): RefundRequest
    {
        $user = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'Device',
            'device_model' => 'X',
            'status' => 'active',
            'payment_amount' => $amount,
            'customer_email' => 'customer@example.com',
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
            'communication_channels' => [],
        ]);
    }
}
