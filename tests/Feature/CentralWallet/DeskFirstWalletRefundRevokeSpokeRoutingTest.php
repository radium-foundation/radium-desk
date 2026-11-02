<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundRevocationAttemptStatus;
use App\Enums\RefundRevokeCustomerOutcome;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\RefundRevocationAttempt;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxWalletRefundReversalClient;
use App\Services\RdService\RdServiceInWalletRefundReversalClient;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeskFirstWalletRefundRevokeSpokeRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'central_wallet.wallet_refund.desk_first_enabled' => true,
            'central_wallet.wallet_refund.desk_first_spoke_allowlist' => 'rdservice.in,radiumbox.com',
            'commercial_state.enabled' => true,
        ]);
    }

    public function test_radiumbox_desk_first_revoke_posts_central_wallet_audit_retirement(): void
    {
        $creditUrl = 'https://radiumbox.test/api/integrations/v1/wallet-refunds';
        $reversalUrl = 'https://radiumbox.test'.RadiumBoxWalletRefundReversalClient::REVERSAL_PATH;

        Http::fake([
            $creditUrl => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 2588,
                    'wallet_reference' => '2588',
                    'desk_refund_reference' => 'REF-67445',
                    'credit' => '617.00',
                    'currency' => 'INR',
                    'balance' => '0.00',
                ],
            ], 201),
            $reversalUrl => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_reversal_transaction_id' => 8801,
                    'wallet_reversal_reference' => '8801',
                    'desk_refund_reference' => 'REF-67445',
                    'debit' => '0.00',
                    'balance' => '0.00',
                    'destination' => 'central_wallet',
                ],
            ], 201),
        ]);

        config([
            'radiumbox.wallet_refund_credit_enabled' => true,
            'radiumbox.wallet_refund_reversal_enabled' => true,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'box-token',
        ]);

        [$ops, $refund] = $this->pendingWalletRefund('RB1092', '617.00', 'REF-67445');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertSessionHas('status', 'refund-completed');

        $ledgerCredit = CentralWalletLedgerEntry::query()->where('business_reference', 'REF-67445')->firstOrFail();
        $debitCountBefore = CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->count();

        config(['central_wallet.wallet_refund.desk_first_enabled' => false]);

        $this->actingAs($ops)->post(route('refunds.revoke', $refund), [
            'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
            'revoke_reason' => 'Customer wants service.',
        ])->assertSessionHas('status', 'refund-revoked');

        $this->assertSame($debitCountBefore + 1, CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->count());

        Http::assertSent(function ($request) use ($reversalUrl, $ledgerCredit) {
            $data = $request->data();

            return $request->url() === $reversalUrl
                && ($data['central_wallet_authoritative'] ?? false) === true
                && ($data['source_system'] ?? null) === 'radium_desk'
                && ($data['original_wallet_transaction_id'] ?? null) === (string) $ledgerCredit->id;
        });

        $refund->refresh();
        $this->assertSame(RefundStatus::Revoked, $refund->status);
    }

    public function test_rdin_desk_first_revoke_still_routes_to_rdservice_in_audit_retirement(): void
    {
        $creditUrl = 'https://rdservice.in.test/api/integrations/v1/wallet-refunds';
        $reversalUrl = 'https://rdservice.in.test'.RdServiceInWalletRefundReversalClient::REVERSAL_PATH;

        Http::fake([
            $creditUrl => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 1,
                    'wallet_reference' => 'CW:1',
                    'desk_refund_reference' => 'REF-RDIN-1',
                    'credit' => '90.00',
                    'currency' => 'INR',
                    'balance' => '0.00',
                ],
            ], 201),
            $reversalUrl => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_reversal_transaction_id' => 77,
                    'wallet_reversal_reference' => '77',
                    'debit' => '0.00',
                    'balance' => '0.00',
                    'destination' => 'central_wallet',
                ],
            ], 201),
        ]);

        config([
            'rdservice_in.wallet_refund_credit_enabled' => true,
            'rdservice_in.wallet_refund_reversal_enabled' => true,
            'order_lookup.spokes.rdservice_in.enabled' => true,
            'order_lookup.spokes.rdservice_in.base_url' => 'https://rdservice.in.test',
            'order_lookup.spokes.rdservice_in.token' => 'rdin-token',
        ]);

        [$ops, $refund] = $this->pendingWalletRefund('RD90501', '90.00', 'REF-RDIN-1');
        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])->assertSessionHas('status', 'refund-completed');

        config(['central_wallet.wallet_refund.desk_first_enabled' => false]);

        $this->actingAs($ops)->post(route('refunds.revoke', $refund), [
            'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
            'revoke_reason' => 'Customer wants service.',
        ])->assertSessionHas('status', 'refund-revoked');

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://rdservice.in.test')
                && ($request['central_wallet_authoritative'] ?? false) === true;
        });

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'radiumbox.test');
        });
    }

    public function test_legacy_radiumbox_wallet_revoke_uses_spoke_debit_path_without_central_wallet_flag(): void
    {
        $reversalUrl = 'https://radiumbox.test'.RadiumBoxWalletRefundReversalClient::REVERSAL_PATH;

        Http::fake([
            $reversalUrl => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_reversal_transaction_id' => 9001,
                    'wallet_reversal_reference' => '9001',
                    'desk_refund_reference' => 'REF-LEG-RB',
                    'debit' => '499.00',
                    'balance' => '0.00',
                ],
            ], 201),
        ]);

        config([
            'radiumbox.wallet_refund_reversal_enabled' => true,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'box-token',
            'commercial_state.enabled' => true,
        ]);

        $this->seed(RolePermissionSeeder::class);
        $ops = User::factory()->create();
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RB317',
            'serial_number' => 'SN-RB317',
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => 'active',
            'payment_amount' => '499.00',
            'customer_email' => 'customer@example.com',
            'created_by' => $ops->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-LEG-RB',
            'amount' => '499.00',
            'refund_amount' => '499.00',
            'reason' => 'Test',
            'status' => RefundStatus::Closed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $ops->id,
            'execution_transaction_id' => '2559',
            'communication_channels' => [],
        ]);

        $this->actingAs($ops)->post(route('refunds.revoke', $refund), [
            'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
            'revoke_reason' => 'Customer wants service.',
        ])->assertSessionHas('status', 'refund-revoked');

        Http::assertSent(function ($request) use ($reversalUrl) {
            $data = $request->data();

            return $request->url() === $reversalUrl
                && ($data['source_system'] ?? null) === 'radium_desk'
                && ! array_key_exists('central_wallet_authoritative', $data);
        });

        $this->assertSame(0, CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->count());
    }

    public function test_radiumbox_audit_retirement_failure_leaves_refund_revocable_without_duplicate_cw_debit(): void
    {
        $creditUrl = 'https://radiumbox.test/api/integrations/v1/wallet-refunds';
        $reversalUrl = 'https://radiumbox.test'.RadiumBoxWalletRefundReversalClient::REVERSAL_PATH;

        Http::fake([
            $creditUrl => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 99,
                    'wallet_reference' => '99',
                    'desk_refund_reference' => 'REF-PARTIAL',
                    'credit' => '40.00',
                    'currency' => 'INR',
                    'balance' => '0.00',
                ],
            ], 201),
            $reversalUrl => Http::response(['message' => 'Spoke unavailable'], 503),
        ]);

        config([
            'radiumbox.wallet_refund_credit_enabled' => true,
            'radiumbox.wallet_refund_reversal_enabled' => true,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'box-token',
        ]);

        [$ops, $refund] = $this->pendingWalletRefund('RB901', '40.00', 'REF-PARTIAL');
        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])->assertSessionHas('status', 'refund-completed');

        config(['central_wallet.wallet_refund.desk_first_enabled' => false]);

        $debitsBeforeRevoke = CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->count();

        $this->actingAs($ops)->post(route('refunds.revoke', $refund), [
            'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
            'revoke_reason' => 'Customer wants service.',
        ])->assertSessionHasErrors('refund');

        $this->assertSame($debitsBeforeRevoke + 1, CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->count());
        $this->assertTrue($refund->fresh()->status?->isEligibleForRevoke() ?? false);

        $attempt = RefundRevocationAttempt::query()->where('refund_request_id', $refund->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(RefundRevocationAttemptStatus::WalletReversed, $attempt->status);
        $this->assertNotNull($attempt->wallet_reversal_reference);
    }

    /**
     * @return array{0: User, 1: RefundRequest}
     */
    private function pendingWalletRefund(string $orderId, string $amount, string $reference): array
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
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'Radium Device',
            'device_model' => 'Model',
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
            'title' => 'Desk-first revoke routing test',
            'description' => 'Test',
            'status' => 'open',
            'created_by' => $ops->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => $reference,
            'amount' => $amount,
            'refund_amount' => $amount,
            'reason' => 'Test refund',
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
