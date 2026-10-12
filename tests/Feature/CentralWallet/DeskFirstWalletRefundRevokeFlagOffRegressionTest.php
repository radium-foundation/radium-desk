<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
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
use App\Services\RdService\RdServiceInWalletRefundReversalClient;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeskFirstWalletRefundRevokeFlagOffRegressionTest extends TestCase
{
    use RefreshDatabase;

    private const CREDIT_URL = 'https://rdservice.in.test/api/integrations/v1/wallet-refunds';

    private const REVERSAL_URL = 'https://rdservice.in.test'.RdServiceInWalletRefundReversalClient::REVERSAL_PATH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'central_wallet.wallet_refund.desk_first_enabled' => true,
            'rdservice_in.wallet_refund_credit_enabled' => true,
            'rdservice_in.wallet_refund_reversal_enabled' => true,
            'order_lookup.spokes.rdservice_in.enabled' => true,
            'order_lookup.spokes.rdservice_in.base_url' => 'https://rdservice.in.test',
            'order_lookup.spokes.rdservice_in.token' => 'desk-rdservice-wallet-token',
            'commercial_state.enabled' => true,
        ]);
    }

    public function test_revoke_routes_through_desk_first_coordinator_when_flag_off_after_cw_completion(): void
    {
        Http::fake([
            self::CREDIT_URL => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 1,
                    'wallet_reference' => 'CW:1',
                    'desk_refund_reference' => 'REF-FO-1',
                    'credit' => '90.00',
                    'currency' => 'INR',
                    'balance' => '0.00',
                ],
            ], 201),
            self::REVERSAL_URL => Http::response([
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

        [$ops, $refund] = $this->pendingWalletRefund('RD90501', '90.00', 'REF-FO-1');
        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])->assertSessionHas('status', 'refund-completed');

        config(['central_wallet.wallet_refund.desk_first_enabled' => false]);

        $this->actingAs($ops)->post(route('refunds.revoke', $refund), [
            'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
            'revoke_reason' => 'Customer wants service.',
        ])->assertSessionHas('status', 'refund-revoked');

        Http::assertSent(function ($request) {
            return $request->url() === self::REVERSAL_URL
                && ($request['central_wallet_authoritative'] ?? false) === true;
        });

        Http::assertNotSent(function ($request) {
            return $request->url() === self::REVERSAL_URL
                && ! ($request['central_wallet_authoritative'] ?? false);
        });
    }

    public function test_revoke_debit_is_idempotent_when_flag_off(): void
    {
        Http::fake([
            self::CREDIT_URL => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 2,
                    'wallet_reference' => 'CW:2',
                    'desk_refund_reference' => 'REF-FO-2',
                    'credit' => '50.00',
                    'currency' => 'INR',
                    'balance' => '0.00',
                ],
            ], 201),
            self::REVERSAL_URL => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_reversal_transaction_id' => 78,
                    'wallet_reversal_reference' => '78',
                    'debit' => '0.00',
                    'balance' => '0.00',
                    'destination' => 'central_wallet',
                ],
            ], 201),
        ]);

        [$ops, $refund] = $this->pendingWalletRefund('RD90502', '50.00', 'REF-FO-2');
        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])->assertSessionHas('status', 'refund-completed');
        config(['central_wallet.wallet_refund.desk_first_enabled' => false]);

        $payload = [
            'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
            'revoke_reason' => 'Customer wants service.',
        ];
        $this->actingAs($ops)->post(route('refunds.revoke', $refund), $payload)->assertSessionHas('status', 'refund-revoked');

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->count());
    }

    public function test_spoke_retirement_retries_after_timeout_without_second_debit_when_flag_off(): void
    {
        $reversalCalls = 0;
        Http::fake(function ($request) use (&$reversalCalls) {
            if ($request->url() === self::CREDIT_URL) {
                return Http::response([
                    'status' => 201,
                    'data' => [
                        'wallet_transaction_id' => 3,
                        'wallet_reference' => 'CW:3',
                        'desk_refund_reference' => 'REF-FO-3',
                        'credit' => '35.00',
                        'currency' => 'INR',
                        'balance' => '0.00',
                    ],
                ], 201);
            }
            if ($request->url() === self::REVERSAL_URL) {
                $reversalCalls++;
                if ($reversalCalls === 1) {
                    throw new ConnectionException('timeout');
                }

                return Http::response([
                    'status' => 201,
                    'data' => [
                        'wallet_reversal_transaction_id' => 79,
                        'wallet_reversal_reference' => '79',
                        'debit' => '0.00',
                        'balance' => '0.00',
                        'destination' => 'central_wallet',
                    ],
                ], 201);
            }

            return Http::response([], 404);
        });

        [$ops, $refund] = $this->pendingWalletRefund('RD90503', '35.00', 'REF-FO-3');
        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])->assertSessionHas('status', 'refund-completed');
        config(['central_wallet.wallet_refund.desk_first_enabled' => false]);

        $payload = [
            'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
            'revoke_reason' => 'Customer wants service.',
        ];
        $this->actingAs($ops)->post(route('refunds.revoke', $refund), $payload)->assertSessionHasErrors('refund');
        $this->assertSame(RefundRevocationAttemptStatus::WalletReversed, RefundRevocationAttempt::query()->first()?->status);
        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->count());

        $this->actingAs($ops)->post(route('refunds.revoke', $refund), $payload)->assertSessionHas('status', 'refund-revoked');
        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->count());
        $this->assertSame(2, $reversalCalls);
    }

    public function test_legacy_refund_without_cw_reference_uses_legacy_reversal_when_flag_off(): void
    {
        config(['central_wallet.wallet_refund.desk_first_enabled' => false]);

        Http::fake([
            self::REVERSAL_URL => Http::response([
                'status' => 201,
                'message' => 'Wallet debit created',
                'data' => [
                    'wallet_reversal_transaction_id' => 9001,
                    'wallet_reversal_reference' => '9001',
                    'desk_refund_reference' => 'REF-FO-LEG',
                    'debit' => '499.00',
                    'balance' => '0.00',
                ],
            ], 201),
        ]);

        $ops = User::factory()->create();
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD3147',
            'serial_number' => 'SN',
            'product_name' => 'Device',
            'device_model' => 'X',
            'status' => 'active',
            'payment_amount' => '499.00',
            'customer_email' => 'legacy@example.com',
            'created_by' => $ops->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-FO-LEG',
            'amount' => '499.00',
            'refund_amount' => '499.00',
            'reason' => 'Legacy',
            'status' => RefundStatus::Closed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'execution_reference_no' => '2559',
            'execution_transaction_id' => '2559',
            'requested_by' => $ops->id,
            'reviewed_by' => $ops->id,
            'reviewed_at' => now(),
            'executed_by' => $ops->id,
            'executed_at' => now(),
            'closed_at' => now(),
            'communication_channels' => [],
        ]);

        $this->actingAs($ops)->post(route('refunds.revoke', $refund), [
            'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
            'revoke_reason' => 'Customer wants service.',
        ])->assertSessionHas('status', 'refund-revoked');

        Http::assertSent(function ($request) {
            return $request->url() === self::REVERSAL_URL
                && ! array_key_exists('central_wallet_authoritative', $request->data());
        });
    }

    public function test_unsupported_order_family_blocked_for_desk_first_revoke_when_flag_off(): void
    {
        [$ops, $refund, $entryId] = $this->seedClosedDeskFirstRefund('RDE318801', 'REF-FO-RBX', '75.00');
        config(['central_wallet.wallet_refund.desk_first_enabled' => false]);

        $this->actingAs($ops)->post(route('refunds.revoke', $refund), [
            'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
            'revoke_reason' => 'Customer wants service.',
        ])->assertSessionHasErrors('refund');

        $this->assertSame(0, CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->count());
    }

    public function test_insufficient_balance_fails_closed_when_flag_off(): void
    {
        Http::fake([
            self::CREDIT_URL => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 4,
                    'wallet_reference' => 'CW:4',
                    'desk_refund_reference' => 'REF-FO-BAL',
                    'credit' => '500.00',
                    'currency' => 'INR',
                    'balance' => '0.00',
                ],
            ], 201),
        ]);

        [$ops, $refund, $cwid] = $this->pendingWalletRefund('RD90504', '500.00', 'REF-FO-BAL');
        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])->assertSessionHas('status', 'refund-completed');

        CentralWalletLedgerEntry::query()->create([
            'central_wallet_id' => $cwid,
            'entry_type' => LedgerEntryType::Debit,
            'amount' => '500.00',
            'currency' => 'INR',
            'status' => LedgerEntryStatus::Posted,
            'source_system' => 'test',
            'correlation_id' => (string) Str::uuid(),
            'posted_at' => now(),
        ]);

        config(['central_wallet.wallet_refund.desk_first_enabled' => false]);

        $debitsBefore = CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->count();
        $this->actingAs($ops)->post(route('refunds.revoke', $refund), [
            'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
            'revoke_reason' => 'Customer wants service.',
        ])->assertSessionHasErrors('refund');

        $this->assertSame($debitsBefore, CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->count());
        $this->assertTrue($refund->fresh()->status?->isEligibleForRevoke() ?? false);
    }

    /**
     * @return array{0: User, 1: RefundRequest, 2: string}
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
            'device_model' => 'Model X',
            'status' => 'active',
            'payment_amount' => $amount,
            'customer_email' => 'flag-off@example.com',
            'created_by' => $ops->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-'.substr($reference, -6),
            'category' => 'Refund',
            'source' => 'internal',
            'title' => 'Flag-off revoke test',
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
            'reason' => 'Flag-off revoke test',
            'status' => RefundStatus::PendingExecution,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $ops->id,
            'reviewed_by' => $ops->id,
            'reviewed_at' => now(),
            'communication_channels' => [],
        ]);

        return [$ops, $refund, $cwid];
    }

    /**
     * @return array{0: User, 1: RefundRequest, 2: int}
     */
    private function seedClosedDeskFirstRefund(string $orderId, string $reference, string $amount): array
    {
        [$ops, $refund, $cwid] = $this->pendingWalletRefund($orderId, $amount, $reference);
        $entry = CentralWalletLedgerEntry::query()->create([
            'central_wallet_id' => $cwid,
            'entry_type' => LedgerEntryType::Credit,
            'amount' => $amount,
            'currency' => 'INR',
            'status' => LedgerEntryStatus::Posted,
            'source_system' => 'rdservice.in',
            'business_reference' => $reference,
            'correlation_id' => (string) Str::uuid(),
            'posted_at' => now(),
        ]);
        $refund->update([
            'status' => RefundStatus::Closed,
            'execution_reference_no' => 'CW:'.$entry->id,
            'execution_transaction_id' => (string) $entry->id,
            'executed_by' => $ops->id,
            'executed_at' => now(),
            'closed_at' => now(),
        ]);

        return [$ops, $refund->fresh(), (int) $entry->id];
    }
}
