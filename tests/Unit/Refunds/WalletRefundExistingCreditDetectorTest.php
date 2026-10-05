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

    public function test_radiumbox_refunds_use_central_wallet_detector(): void
    {
        $refund = $this->makeRefund('RB484', 'REF-UNIT-BOX', '100.00');

        $this->assertTrue($this->detector->supports($refund));
    }

    public function test_detects_radiumbox_existing_credit_exact_match(): void
    {
        $refund = $this->makeRefund('RB484', 'REF-67363', '849.00');
        $cwid = '5a3d0706-9f4b-4adc-b3d8-7cd134295404';
        $this->seedPostedCreditLedgerEntry(
            cwid: $cwid,
            businessReference: 'REF-67363',
            orderId: 'RB484',
            amount: '849.00',
            sourceSystem: 'radiumbox.com',
            ledgerEntryId: 54,
        );

        $result = $this->detector->detect($refund);

        $this->assertTrue($result->isMatched());
        $this->assertSame('CW:54', $result->walletReference());
        $this->assertSame(54, $result->ledgerEntry()?->id);
    }

    public function test_radiumbox_reference_mismatch_is_not_found(): void
    {
        $refund = $this->makeRefund('RB484', 'REF-67363', '849.00');
        $this->seedPostedCreditLedgerEntry(
            cwid: (string) Str::uuid(),
            businessReference: 'REF-OTHER',
            orderId: 'RB484',
            amount: '849.00',
            sourceSystem: 'radiumbox.com',
        );

        $this->assertTrue($this->detector->detect($refund)->isNotFound());
    }

    public function test_radiumbox_amount_mismatch_is_blocking(): void
    {
        $refund = $this->makeRefund('RB484', 'REF-67363', '849.00');
        $this->seedPostedCreditLedgerEntry(
            cwid: (string) Str::uuid(),
            businessReference: 'REF-67363',
            orderId: 'RB484',
            amount: '848.00',
            sourceSystem: 'radiumbox.com',
        );

        $result = $this->detector->detect($refund);
        $this->assertSame(WalletRefundExistingCreditDetection::STATUS_AMOUNT_MISMATCH, $result->status());
        $this->assertTrue($result->isBlocking());
    }

    public function test_radiumbox_source_reference_mismatch_is_blocking(): void
    {
        $refund = $this->makeRefund('RB484', 'REF-67363', '849.00');
        $cwid = (string) Str::uuid();
        $this->seedCentralWallet($cwid);

        \DB::table('central_wallet_ledger_entries')->insert([
            'central_wallet_id' => $cwid,
            'entry_type' => 'credit',
            'amount' => '849.00',
            'currency' => 'INR',
            'status' => 'posted',
            'source_system' => 'radiumbox.com',
            'source_reference' => 'desk_refund:RB999',
            'correlation_id' => (string) Str::uuid(),
            'business_reference' => 'REF-67363',
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->detector->detect($refund);
        $this->assertSame(WalletRefundExistingCreditDetection::STATUS_SOURCE_MISMATCH, $result->status());
    }

    public function test_radiumbox_ambiguous_credits_are_blocking(): void
    {
        $refund = $this->makeRefund('RB484', 'REF-67363', '849.00');
        $cwid = (string) Str::uuid();
        $this->seedPostedCreditLedgerEntry($cwid, 'REF-67363', 'RB484', '849.00', 'radiumbox.com', 801);
        $this->seedPostedCreditLedgerEntry($cwid, 'REF-67363', 'RB484', '849.00', 'radiumbox.com', 802);

        $result = $this->detector->detect($refund);
        $this->assertSame(WalletRefundExistingCreditDetection::STATUS_AMBIGUOUS, $result->status());
        $this->assertTrue($result->isBlocking());
    }

    public function test_radiumbox_reversed_credit_is_not_usable(): void
    {
        $refund = $this->makeRefund('RB484', 'REF-67363', '849.00');
        $cwid = (string) Str::uuid();
        $ledgerId = $this->seedPostedCreditLedgerEntry(
            cwid: $cwid,
            businessReference: 'REF-67363',
            orderId: 'RB484',
            amount: '849.00',
            sourceSystem: 'radiumbox.com',
        );
        $this->seedPostedReversalForEntry($ledgerId, $cwid, 'REF-67363-REV');

        $result = $this->detector->detect($refund);
        $this->assertSame(WalletRefundExistingCreditDetection::STATUS_REVERSED, $result->status());
    }

    public function test_radiumbox_consumed_credit_is_not_usable(): void
    {
        $refund = $this->makeRefund('RB484', 'REF-67363', '849.00');
        $cwid = (string) Str::uuid();
        $ledgerId = $this->seedPostedCreditLedgerEntry(
            cwid: $cwid,
            businessReference: 'REF-67363',
            orderId: 'RB484',
            amount: '849.00',
            sourceSystem: 'radiumbox.com',
            ledgerEntryId: 54,
        );

        $user = User::factory()->create();
        RefundRequest::query()->create([
            'order_id' => $refund->order_id,
            'reference_no' => 'REF-67363-OTHER',
            'amount' => '849.00',
            'refund_amount' => '849.00',
            'reason' => 'Already completed against CW:54.',
            'status' => RefundStatus::Closed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'execution_reference_no' => 'CW:'.$ledgerId,
            'execution_transaction_id' => (string) $ledgerId,
            'executed_at' => now(),
            'requested_by' => $user->id,
            'communication_channels' => [],
        ]);

        $result = $this->detector->detect($refund);
        $this->assertSame(WalletRefundExistingCreditDetection::STATUS_ALREADY_CONSUMED, $result->status());
    }

    public function test_radiumbox_credit_from_other_source_system_is_ignored(): void
    {
        $refund = $this->makeRefund('RB484', 'REF-67363', '849.00');
        $this->seedPostedCreditLedgerEntry(
            cwid: (string) Str::uuid(),
            businessReference: 'REF-67363',
            orderId: 'RB484',
            amount: '849.00',
            sourceSystem: 'rdservice.in',
        );

        $this->assertTrue($this->detector->detect($refund)->isNotFound());
    }

    public function test_rdservice_net_existing_credit_still_matches(): void
    {
        config([
            'rdservice_net.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.rdservice_net.enabled' => true,
            'order_lookup.spokes.rdservice_net.base_url' => 'https://rdservice.net.test',
            'order_lookup.spokes.rdservice_net.token' => 'token',
        ]);

        $refund = $this->makeRefund('RN159', 'REF-UNIT-NET', '499.00');
        $cwid = (string) Str::uuid();
        $this->seedPostedCreditLedgerEntry(
            cwid: $cwid,
            businessReference: 'REF-UNIT-NET',
            orderId: 'RN159',
            amount: '499.00',
            sourceSystem: 'rdservice.net',
        );

        $result = $this->detector->detect($refund);

        $this->assertTrue($result->isMatched());
        $this->assertSame('CW:'.$result->ledgerEntry()?->id, $result->walletReference());
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
