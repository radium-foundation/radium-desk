<?php

namespace Tests\Feature\Refunds;

use App\CentralWallet\Application\WalletRefundReconciliationService;
use App\CentralWallet\Infrastructure\Jobs\ReconciliationDailyJob;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletReconciliationItem;
use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\CentralWalletLedgerTestHelper;
use Tests\TestCase;

class WalletRefundExistingCreditRecoveryTest extends TestCase
{
    use CentralWalletLedgerTestHelper;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'rdservice_in.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.rdservice_in.enabled' => true,
            'order_lookup.spokes.rdservice_in.base_url' => 'https://rdservice.in.test',
            'order_lookup.spokes.rdservice_in.token' => 'desk-rdservice-wallet-token',
            'rdservice_net.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.rdservice_net.enabled' => true,
            'order_lookup.spokes.rdservice_net.base_url' => 'https://rdservice.net.test',
            'order_lookup.spokes.rdservice_net.token' => 'desk-rdservice-net-wallet-token',
        ]);
    }

    public function test_normal_wallet_refund_still_credits_via_spoke_and_completes(): void
    {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 91001,
                    'wallet_reference' => 'CW:91001',
                    'desk_refund_reference' => 'REF-GATE1-NORMAL',
                    'credit' => '499.00',
                    'currency' => 'INR',
                    'balance' => '499.00',
                ],
            ], 201),
        ]);

        [$ops, $refund] = $this->pendingWalletRefundFixture('RD3437999', '499.00', 'REF-GATE1-NORMAL');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-completed');

        $refund->refresh();
        $this->assertContains($refund->status, [RefundStatus::Completed, RefundStatus::Closed]);
        $this->assertSame('CW:91001', $refund->execution_reference_no);

        Http::assertSentCount(1);
        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
    }

    public function test_cw_id_response_from_spoke_still_completes(): void
    {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 73,
                    'wallet_reference' => 'CW:73',
                    'desk_refund_reference' => 'REF-GATE1-CW73',
                    'credit' => '499.00',
                    'currency' => 'INR',
                    'balance' => '499.00',
                ],
            ], 201),
        ]);

        [$ops, $refund] = $this->pendingWalletRefundFixture('RD13612', '499.00', 'REF-GATE1-CW73');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertSessionHas('status', 'refund-completed');

        $refund->refresh();
        $this->assertSame('CW:73', $refund->execution_reference_no);
        $this->assertSame('73', $refund->execution_transaction_id);
    }

    public function test_ref67363_radiumbox_regression_contract_recovery_without_second_credit(): void
    {
        Http::fake();

        $cwid = '5a3d0706-9f4b-4adc-b3d8-7cd134295404';
        $ledgerId = $this->seedPostedCreditLedgerEntry(
            cwid: $cwid,
            businessReference: 'REF-67363',
            orderId: 'RB484',
            amount: '849.00',
            sourceSystem: 'radiumbox.com',
            ledgerEntryId: 54,
        );

        [$ops, $refund] = $this->pendingWalletRefundFixture('RB484', '849.00', 'REF-67363');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-completed');

        $refund->refresh();
        $this->assertContains($refund->status, [RefundStatus::Completed, RefundStatus::Closed]);
        $this->assertSame('CW:'.$ledgerId, $refund->execution_reference_no);
        $this->assertSame((string) $ledgerId, $refund->execution_transaction_id);

        Http::assertNothingSent();
        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', 'credit')->count());

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'refund.wallet_credit_reconciled',
            'auditable_type' => RefundRequest::class,
            'auditable_id' => $refund->id,
        ]);
    }

    public function test_ref67379_regression_contract_recovery_without_second_credit(): void
    {
        Http::fake();

        $cwid = (string) Str::uuid();
        $ledgerId = $this->seedPostedCreditLedgerEntry(
            cwid: $cwid,
            businessReference: 'REF-GATE1-REGRESSION',
            orderId: 'RD13612',
            amount: '499.00',
        );

        [$ops, $refund] = $this->pendingWalletRefundFixture('RD13612', '499.00', 'REF-GATE1-REGRESSION');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-completed');

        $refund->refresh();
        $this->assertContains($refund->status, [RefundStatus::Completed, RefundStatus::Closed]);
        $this->assertSame('CW:'.$ledgerId, $refund->execution_reference_no);
        $this->assertSame((string) $ledgerId, $refund->execution_transaction_id);

        Http::assertNothingSent();
        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', 'credit')->count());

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'refund.wallet_credit_reconciled',
            'auditable_type' => RefundRequest::class,
            'auditable_id' => $refund->id,
        ]);

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertSessionHasErrors('refund');

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', 'credit')->count());
    }

    public function test_existing_credit_amount_mismatch_fails_safely(): void
    {
        Http::fake();

        $this->seedPostedCreditLedgerEntry(
            cwid: (string) Str::uuid(),
            businessReference: 'REF-GATE1-MISMATCH',
            orderId: 'RD3437001',
            amount: '400.00',
        );

        [$ops, $refund] = $this->pendingWalletRefundFixture('RD3437001', '499.00', 'REF-GATE1-MISMATCH');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertSessionHasErrors('refund');

        Http::assertNothingSent();
        $refund->refresh();
        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
    }

    public function test_existing_credit_reversed_fails_safely(): void
    {
        Http::fake();

        $cwid = (string) Str::uuid();
        $ledgerId = $this->seedPostedCreditLedgerEntry(
            cwid: $cwid,
            businessReference: 'REF-GATE1-REVERSED',
            orderId: 'RD3437002',
            amount: '100.00',
        );
        $this->seedPostedReversalForEntry($ledgerId, $cwid, 'REF-GATE1-REVERSED-REV');

        [$ops, $refund] = $this->pendingWalletRefundFixture('RD3437002', '100.00', 'REF-GATE1-REVERSED');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertSessionHasErrors('refund');

        Http::assertNothingSent();
    }

    public function test_existing_credit_already_consumed_by_another_refund_fails_safely(): void
    {
        Http::fake();

        $cwid = (string) Str::uuid();
        $ledgerId = $this->seedPostedCreditLedgerEntry(
            cwid: $cwid,
            businessReference: 'REF-GATE1-CONSUMED',
            orderId: 'RD3437003',
            amount: '250.00',
        );

        $ops = User::factory()->create();
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD3437003',
            'serial_number' => 'SN-RD3437003',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'payment_amount' => '250.00',
            'customer_email' => 'customer@example.com',
            'created_by' => $ops->id,
        ]);

        RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-GATE1-CONSUMED-OTHER',
            'amount' => '250.00',
            'refund_amount' => '250.00',
            'reason' => 'Already completed against this ledger entry.',
            'status' => RefundStatus::Completed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'execution_reference_no' => 'CW:'.$ledgerId,
            'execution_transaction_id' => (string) $ledgerId,
            'executed_at' => now(),
            'requested_by' => $ops->id,
            'communication_channels' => [],
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-GATE1-CONSUMED',
            'amount' => '250.00',
            'refund_amount' => '250.00',
            'reason' => 'Pending refund with matching ledger credit.',
            'status' => RefundStatus::PendingExecution,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $ops->id,
            'reviewed_by' => $ops->id,
            'reviewed_at' => now(),
            'communication_channels' => [],
        ]);

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertSessionHasErrors('refund');
    }

    public function test_ambiguous_existing_credits_remain_unresolved(): void
    {
        Http::fake();

        $cwid = (string) Str::uuid();
        $this->seedPostedCreditLedgerEntry($cwid, 'REF-GATE1-AMBIG', 'RD3437004', '100.00', ledgerEntryId: 701);
        $this->seedPostedCreditLedgerEntry($cwid, 'REF-GATE1-AMBIG', 'RD3437004', '100.00', ledgerEntryId: 702);

        [$ops, $refund] = $this->pendingWalletRefundFixture('RD3437004', '100.00', 'REF-GATE1-AMBIG');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertSessionHasErrors('refund');

        Http::assertNothingSent();
    }

    public function test_unauthorized_user_cannot_recover_wallet_refund(): void
    {
        Http::fake();

        $cwid = (string) Str::uuid();
        $this->seedPostedCreditLedgerEntry($cwid, 'REF-GATE1-AUTH', 'RD3437005', '100.00');

        [$ops, $refund] = $this->pendingWalletRefundFixture('RD3437005', '100.00', 'REF-GATE1-AUTH');
        $viewer = User::factory()->create();
        $viewer->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($viewer)
            ->post(route('refunds.complete', $refund), [])
            ->assertForbidden();
    }

    public function test_recovery_show_page_displays_safe_complete_action(): void
    {
        $cwid = (string) Str::uuid();
        $ledgerId = $this->seedPostedCreditLedgerEntry(
            cwid: $cwid,
            businessReference: 'REF-GATE1-UI',
            orderId: 'RD3437006',
            amount: '499.00',
        );

        [$ops, $refund] = $this->pendingWalletRefundFixture('RD3437006', '499.00', 'REF-GATE1-UI');

        $this->actingAs($ops)
            ->get(route('refunds.show', $refund))
            ->assertOk()
            ->assertSee('Wallet credit already completed')
            ->assertSee('CW:'.$ledgerId)
            ->assertSee('Safely Complete Refund')
            ->assertDontSee('name="execution_reference_no"', false);
    }

    public function test_reconciliation_job_detects_stranded_refund_without_creating_credit(): void
    {
        config(['central_wallet.reconciliation.enabled' => true]);

        $cwid = (string) Str::uuid();
        $ledgerId = $this->seedPostedCreditLedgerEntry(
            cwid: $cwid,
            businessReference: 'REF-GATE1-RECON',
            orderId: 'RD3437007',
            amount: '150.00',
        );

        [$ops, $refund] = $this->pendingWalletRefundFixture('RD3437007', '150.00', 'REF-GATE1-RECON');
        unset($ops, $refund);

        $beforeCredits = CentralWalletLedgerEntry::query()->count();

        (new ReconciliationDailyJob)->handle(app(WalletRefundReconciliationService::class));

        $this->assertSame($beforeCredits, CentralWalletLedgerEntry::query()->count());

        $this->assertDatabaseHas('central_wallet_reconciliation_items', [
            'item_type' => 'wallet_refund_stranded_with_credit',
        ]);

        $item = CentralWalletReconciliationItem::query()->first();
        $this->assertSame('REF-GATE1-RECON', data_get($item?->details, 'business_reference'));
        $this->assertSame($ledgerId, data_get($item?->details, 'wallet_ledger_entry_id'));
    }

    public function test_duplicate_complete_does_not_create_duplicate_credit(): void
    {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 99,
                    'wallet_reference' => 'CW:99',
                    'desk_refund_reference' => 'REF-GATE1-DUP',
                    'credit' => '50.00',
                    'currency' => 'INR',
                    'balance' => '50.00',
                ],
            ], 201),
        ]);

        [$ops, $refund] = $this->pendingWalletRefundFixture('RD3437008', '50.00', 'REF-GATE1-DUP');

        $this->actingAs($ops)->post(route('refunds.complete', $refund), []);
        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])->assertSessionHasErrors('refund');

        Http::assertSentCount(1);
    }

    /**
     * @return array{0: User, 1: RefundRequest}
     */
    private function pendingWalletRefundFixture(string $orderId, string $amount, string $reference): array
    {
        $ops = User::factory()->create();
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'payment_amount' => $amount,
            'customer_email' => 'customer@example.com',
            'created_by' => $ops->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-'.substr($reference, -6),
            'category' => 'Refund',
            'source' => 'internal',
            'title' => 'Wallet refund recovery test',
            'description' => 'Wallet refund recovery test incident.',
            'status' => 'open',
            'created_by' => $ops->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => $reference,
            'amount' => $amount,
            'refund_amount' => $amount,
            'reason' => 'Wallet refund recovery test.',
            'status' => RefundStatus::PendingExecution,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $ops->id,
            'reviewed_by' => $ops->id,
            'reviewed_at' => now(),
            'communication_channels' => [],
        ]);

        return [$ops, $refund];
    }
}
