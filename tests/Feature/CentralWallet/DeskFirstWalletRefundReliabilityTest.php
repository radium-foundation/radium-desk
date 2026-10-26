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
use App\Services\RdService\RdServiceInWalletRefundReversalClient;
use App\Support\Refunds\DeskFirstWalletRefundRecoveryInspector;
use App\Support\Refunds\DeskFirstWalletRefundSupport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeskFirstWalletRefundReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private const SPOKE_CREDIT_URL = 'https://rdservice.in.test/api/integrations/v1/wallet-refunds';

    private const SPOKE_REVERSAL_URL = 'https://rdservice.in.test/api/integrations/v1/wallet-refund-reversals';

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

    public function test_partial_revoke_reuses_cw_debit_and_completes_spoke_retirement_on_retry(): void
    {
        $reversalCalls = 0;
        Http::fake(function ($request) use (&$reversalCalls) {
            if ($request->url() === self::SPOKE_CREDIT_URL) {
                return Http::response([
                    'status' => 201,
                    'data' => [
                        'wallet_transaction_id' => 1,
                        'wallet_reference' => 'CW:1',
                        'desk_refund_reference' => 'REF-REL-R1',
                        'credit' => '60.00',
                        'currency' => 'INR',
                        'balance' => '0.00',
                    ],
                ], 201);
            }

            if ($request->url() === 'https://rdservice.in.test'.RdServiceInWalletRefundReversalClient::REVERSAL_PATH) {
                $reversalCalls++;
                if ($reversalCalls === 1) {
                    throw new ConnectionException('timeout');
                }

                return Http::response([
                    'status' => 201,
                    'data' => [
                        'wallet_reversal_transaction_id' => 55,
                        'wallet_reversal_reference' => '55',
                        'desk_refund_reference' => 'REF-REL-R1',
                        'debit' => '0.00',
                        'balance' => '0.00',
                        'destination' => 'central_wallet',
                    ],
                ], 201);
            }

            return Http::response([], 404);
        });

        [$ops, $refund] = $this->pendingWalletRefund('RD90301', '60.00', 'REF-REL-R1');
        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])->assertSessionHas('status', 'refund-completed');

        $this->actingAs($ops)->post(route('refunds.revoke', $refund), [
            'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
            'revoke_reason' => 'Customer wants service.',
        ])->assertSessionHasErrors('refund');

        $refund->refresh();
        $this->assertTrue($refund->status?->isEligibleForRevoke() ?? false);
        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->count());

        $attempt = RefundRevocationAttempt::query()->where('refund_request_id', $refund->id)->first();
        $this->assertSame(RefundRevocationAttemptStatus::WalletReversed, $attempt?->status);

        $this->actingAs($ops)->post(route('refunds.revoke', $refund), [
            'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
            'revoke_reason' => 'Customer wants service.',
        ])->assertSessionHas('status', 'refund-revoked');

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->count());
        $this->assertSame(2, $reversalCalls);
        Http::assertSent(fn ($req) => ($req['central_wallet_authoritative'] ?? false) === true);
    }

    public function test_recovery_inspector_surfaces_partial_completion_guidance(): void
    {
        Http::fake([
            self::SPOKE_CREDIT_URL => fn () => throw new ConnectionException('timeout'),
        ]);

        [$ops, $refund] = $this->pendingWalletRefund('RD90302', '45.00', 'REF-REL-C1');
        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])->assertSessionHasErrors('refund');

        $notice = app(DeskFirstWalletRefundRecoveryInspector::class)->completionRecoveryNotice($refund->fresh());
        $this->assertNotNull($notice);
        $this->assertStringContainsString('Complete again', $notice);
    }

    public function test_desk_first_disabled_uses_legacy_path_for_rdservice_in(): void
    {
        config([
            'central_wallet.wallet_refund.desk_first_enabled' => false,
            'central_wallet.wallet_refund.desk_authoritative_enabled' => false,
        ]);

        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 900,
                    'wallet_reference' => '900',
                    'desk_refund_reference' => 'REF-LEG-1',
                    'credit' => '30.00',
                    'currency' => 'INR',
                    'balance' => '30.00',
                ],
            ], 201),
        ]);

        [$ops, $refund] = $this->pendingWalletRefund('RD90303', '30.00', 'REF-LEG-1');
        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])->assertSessionHas('status', 'refund-completed');

        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
        Http::assertSent(fn ($req) => ! isset($req['central_wallet_ledger_entry_id']));
    }

    public function test_revoke_recovery_notice_when_cw_debit_posted_and_attempt_failed(): void
    {
        $creditId = $this->seedDeskFirstCompletedRefund();

        CentralWalletLedgerEntry::query()->create([
            'central_wallet_id' => CentralCustomer::query()->value('central_wallet_id'),
            'entry_type' => LedgerEntryType::Debit,
            'amount' => '70.00',
            'currency' => 'INR',
            'status' => 'posted',
            'source_system' => 'radium-desk',
            'source_reference' => DeskFirstWalletRefundSupport::revokeLedgerSourceReference($creditId['refund_id']),
            'correlation_id' => (string) Str::uuid(),
            'posted_at' => now(),
        ]);

        RefundRevocationAttempt::query()->create([
            'refund_request_id' => $creditId['refund_id'],
            'idempotency_key' => 'refund-revoke:'.$creditId['refund_id'],
            'customer_outcome' => RefundRevokeCustomerOutcome::WantsService,
            'revoke_reason' => 'failed spoke',
            'status' => RefundRevocationAttemptStatus::Failed,
            'actor_user_id' => $creditId['ops_id'],
        ]);

        $refund = RefundRequest::query()->findOrFail($creditId['refund_id']);
        $notice = app(DeskFirstWalletRefundRecoveryInspector::class)->revocationRecoveryNotice($refund);
        $this->assertNotNull($notice);
        $this->assertStringContainsString('Revoke again', $notice);
    }

    /**
     * @return array{refund_id: int, ops_id: int}
     */
    private function seedDeskFirstCompletedRefund(): array
    {
        [$ops, $refund] = $this->pendingWalletRefund('RD90399', '70.00', 'REF-REL-N1');
        $entry = CentralWalletLedgerEntry::query()->create([
            'central_wallet_id' => CentralCustomer::query()->value('central_wallet_id'),
            'entry_type' => LedgerEntryType::Credit,
            'amount' => '70.00',
            'currency' => 'INR',
            'status' => 'posted',
            'source_system' => 'rdservice.in',
            'business_reference' => 'REF-REL-N1',
            'correlation_id' => (string) Str::uuid(),
            'posted_at' => now(),
        ]);
        $refund->update([
            'status' => RefundStatus::Closed,
            'execution_reference_no' => 'CW:'.$entry->id,
            'execution_transaction_id' => (string) $entry->id,
        ]);

        return ['refund_id' => $refund->id, 'ops_id' => $ops->id];
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
            'device_model' => 'Model X',
            'status' => 'active',
            'payment_amount' => $amount,
            'customer_email' => 'reliability@example.com',
            'created_by' => $ops->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-'.substr($reference, -6),
            'category' => 'Refund',
            'source' => 'internal',
            'title' => 'Reliability test',
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
            'reason' => 'Reliability test',
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
