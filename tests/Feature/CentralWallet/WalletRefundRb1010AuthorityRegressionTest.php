<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\WalletRefundAccountingDiscrepancyClassifier;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Refunds\DeskFirstWalletRefundCompletionGate;
use App\Services\Refunds\WalletRefundExecutor;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * RB1010 / REF-67435 class: legacy RadiumBox spendable credit without Desk ledger must not complete when authoritative.
 */
class WalletRefundRb1010AuthorityRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'central_wallet.wallet_refund.desk_authoritative_enabled' => true,
            'central_wallet.wallet_refund.desk_first_enabled' => true,
            'central_wallet.wallet_refund.desk_first_spoke_allowlist' => 'rdservice.in,rdservice.net,radiumbox.com',
            'radiumbox.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'desk-box-wallet-token',
        ]);
    }

    public function test_legacy_radiumbox_execution_cannot_complete_under_desk_authoritative_policy(): void
    {
        $refund = $this->rb1010ShapedRefund();

        $gate = app(DeskFirstWalletRefundCompletionGate::class);

        $this->expectException(ValidationException::class);
        $gate->assertMayComplete($refund, [
            'provider' => 'radiumbox_wallet',
            'reference_number' => 'RD2586',
            'transaction_id' => '2586',
            'remarks' => 'Wallet credited automatically via RadiumBox integration.',
            'metadata' => [
                'desk_refund_reference' => 'REF-67435',
                'wallet_balance' => 599.0,
            ],
        ]);
    }

    public function test_classifier_flags_closed_wallet_refund_without_desk_ledger_as_policy_gap(): void
    {
        $refund = $this->rb1010ShapedRefund(closed: true);
        $refund->update([
            'execution_reference_no' => 'RD2586',
            'execution_transaction_id' => '2586',
            'refund_transaction_id' => '2586',
            'executed_at' => now(),
            'closed_at' => now(),
        ]);

        $gaps = app(WalletRefundAccountingDiscrepancyClassifier::class)
            ->authorityGapsForDeskOrder((int) $refund->order_id);

        $this->assertCount(1, $gaps);
        $this->assertSame('REF-67435', $gaps[0]['reference_no']);
        $this->assertSame('599.00', number_format((float) $gaps[0]['refund_amount'], 2, '.', ''));
    }

    public function test_desk_first_radiumbox_refund_posts_ledger_before_spoke_audit(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'message' => 'Central Wallet audit recorded',
                'data' => [
                    'wallet_transaction_id' => 2586,
                    'wallet_reference' => 'CW-AUDIT',
                    'desk_refund_reference' => 'REF-67435',
                    'credit' => '599.00',
                    'currency' => 'INR',
                    'balance' => '0.00',
                    'destination' => 'central_wallet',
                ],
            ], 201),
        ]);

        [$ops, $refund] = $this->pendingRb1010Refund();

        $execution = app(WalletRefundExecutor::class)->execute($refund, $ops, []);

        app(DeskFirstWalletRefundCompletionGate::class)->assertMayComplete($refund, $execution);

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', 'REF-67435')->count());
        $entry = CentralWalletLedgerEntry::query()->where('business_reference', 'REF-67435')->firstOrFail();
        $this->assertSame(LedgerEntryType::Credit, $entry->entry_type);
        $this->assertTrue(($execution['metadata']['desk_first'] ?? false) === true);

        Http::assertSent(function ($request) use ($entry) {
            return $request->url() === 'https://radiumbox.test/api/integrations/v1/wallet-refunds'
                && ($request['central_wallet_ledger_entry_id'] ?? null) === $entry->id
                && $request['desk_refund_reference'] === 'REF-67435'
                && $request['order_id'] === 'RB1010';
        });
    }

    public function test_executor_rejects_legacy_path_when_authoritative_without_desk_first(): void
    {
        config(['central_wallet.wallet_refund.desk_first_enabled' => false]);

        $refund = $this->rb1010ShapedRefund();
        $actor = User::factory()->create();

        $executor = app(WalletRefundExecutor::class);

        $this->expectException(ValidationException::class);
        $executor->execute($refund, $actor, []);
    }

    private function rb1010ShapedRefund(bool $closed = false): RefundRequest
    {
        $customerId = (string) Str::uuid();
        $cwid = (string) Str::uuid();
        CentralWallet::query()->create(['id' => $cwid, 'status' => 'active']);
        CentralCustomer::query()->create([
            'id' => $customerId,
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        $ops = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RB1010',
            'customer_id' => $customerId,
            'serial_number' => 'SN-RB1010',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'payment_amount' => '599.00',
            'customer_email' => 'customer@example.com',
            'created_by' => $ops->id,
        ]);

        return RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-67435',
            'amount' => '599.00',
            'refund_amount' => '599.00',
            'reason' => 'RB1010 shaped regression',
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'status' => $closed ? RefundStatus::Closed : RefundStatus::Approved,
            'requested_by' => $ops->id,
        ]);
    }

    /**
     * @return array{0: User, 1: RefundRequest}
     */
    private function pendingRb1010Refund(): array
    {
        $ops = User::factory()->create();
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $customerId = (string) Str::uuid();
        $cwid = (string) Str::uuid();
        CentralWallet::query()->create(['id' => $cwid, 'status' => 'active']);
        CentralCustomer::query()->create([
            'id' => $customerId,
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        $order = Order::query()->create([
            'order_id' => 'RB1010',
            'customer_id' => $customerId,
            'serial_number' => 'SN-RB1010',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'payment_amount' => '599.00',
            'customer_email' => 'customer@example.com',
            'created_by' => $ops->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC67346',
            'category' => 'Refund',
            'source' => 'internal',
            'title' => 'RB1010 refund',
            'description' => 'Test',
            'status' => 'open',
            'created_by' => $ops->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-67435',
            'amount' => '599.00',
            'refund_amount' => '599.00',
            'reason' => 'RB1010 desk-first path',
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'status' => RefundStatus::PendingExecution,
            'requested_by' => $ops->id,
            'reviewed_by' => $ops->id,
            'reviewed_at' => now(),
            'communication_channels' => [],
        ]);

        return [$ops, $refund];
    }
}
