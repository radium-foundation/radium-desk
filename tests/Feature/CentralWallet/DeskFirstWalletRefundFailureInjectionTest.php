<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundRevocationAttemptStatus;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\RefundRevocationAttempt;
use App\Models\User;
use App\Services\Refunds\DeskFirstWalletRefundOrchestrator;
use App\Support\Refunds\DeskFirstWalletRefundRecoveryInspector;
use App\Support\Refunds\DeskFirstWalletRefundSupport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Failure-injection matrix for desk-first wallet refund completion and revocation.
 */
class DeskFirstWalletRefundFailureInjectionTest extends TestCase
{
    use RefreshDatabase;

    private const SPOKE_URL = 'https://rdservice.in.test/api/integrations/v1/wallet-refunds';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'central_wallet.wallet_refund.desk_first_enabled' => true,
            'rdservice_in.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.rdservice_in.enabled' => true,
            'order_lookup.spokes.rdservice_in.base_url' => 'https://rdservice.in.test',
            'order_lookup.spokes.rdservice_in.token' => 'desk-rdservice-wallet-token',
        ]);
    }

    /** Scenario D/F: spoke + CW credit succeeded in executor, Desk final txn not run yet. */
    public function test_scenario_d_orchestrator_success_then_complete_http_finalizes_without_duplicate_credit(): void
    {
        Http::fake([
            self::SPOKE_URL => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 11,
                    'wallet_reference' => 'CW:11',
                    'desk_refund_reference' => 'REF-FI-D',
                    'credit' => '55.00',
                    'currency' => 'INR',
                    'balance' => '0.00',
                ],
            ], 201),
        ]);

        [$ops, $refund] = $this->pendingWalletRefund('RD90401', '55.00', 'REF-FI-D');

        app(DeskFirstWalletRefundOrchestrator::class)->execute($refund, $ops, [], 'RD90401', '55.00');

        $this->assertSame(RefundStatus::PendingExecution, $refund->fresh()->status);
        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', 'REF-FI-D')->count());

        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])
            ->assertSessionHas('status', 'refund-completed');

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', 'REF-FI-D')->count());
        Http::assertSentCount(2);
    }

    /** Scenario C: uncertain spoke timeout; retry must remain idempotent. */
    public function test_scenario_c_spoke_timeout_after_processing_is_idempotent_on_retry(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new ConnectionException('timeout after upstream processed');
            }

            return Http::response([
                'status' => 200,
                'message' => 'Central Wallet audit already exists',
                'data' => [
                    'wallet_transaction_id' => 12,
                    'wallet_reference' => 'CW:12',
                    'desk_refund_reference' => 'REF-FI-C',
                    'credit' => '33.00',
                    'currency' => 'INR',
                    'balance' => '0.00',
                    'destination' => 'central_wallet',
                ],
            ], 200);
        });

        [$ops, $refund] = $this->pendingWalletRefund('RD90402', '33.00', 'REF-FI-C');

        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])->assertSessionHasErrors('refund');
        $entryId = (int) CentralWalletLedgerEntry::query()->where('business_reference', 'REF-FI-C')->value('id');

        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])->assertSessionHas('status', 'refund-completed');

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', 'REF-FI-C')->count());
        $refund->refresh();
        $this->assertSame('CW:'.$entryId, $refund->execution_reference_no);
        $this->assertSame(2, $calls);
    }

    /** Scenario A (revoke): CW debit committed without attempt wallet_reversed flag. */
    public function test_scenario_a_revoke_debit_without_attempt_state_is_recoverable_without_second_debit(): void
    {
        config([
            'rdservice_in.wallet_refund_reversal_enabled' => true,
            'commercial_state.enabled' => true,
        ]);

        Http::fake([
            self::SPOKE_URL => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 1,
                    'wallet_reference' => 'CW:1',
                    'desk_refund_reference' => 'REF-FI-A',
                    'credit' => '40.00',
                    'currency' => 'INR',
                    'balance' => '0.00',
                ],
            ], 201),
            'https://rdservice.in.test/api/integrations/v1/wallet-refund-reversals' => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_reversal_transaction_id' => 9,
                    'wallet_reversal_reference' => '9',
                    'debit' => '0.00',
                    'balance' => '0.00',
                    'destination' => 'central_wallet',
                ],
            ], 201),
        ]);

        [$ops, $refund] = $this->pendingWalletRefund('RD90403', '40.00', 'REF-FI-A');
        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])->assertSessionHas('status', 'refund-completed');
        $refund->refresh();

        DB::table('central_wallet_ledger_entries')->insert([
            'central_wallet_id' => CentralCustomer::query()->value('central_wallet_id'),
            'entry_type' => LedgerEntryType::Debit->value,
            'amount' => '40.00',
            'currency' => 'INR',
            'status' => 'posted',
            'source_system' => 'radium-desk',
            'source_reference' => DeskFirstWalletRefundSupport::revokeLedgerSourceReference((int) $refund->id),
            'correlation_id' => (string) Str::uuid(),
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        RefundRevocationAttempt::query()->create([
            'refund_request_id' => $refund->id,
            'idempotency_key' => 'refund-revoke:'.$refund->id,
            'customer_outcome' => 'wants_service',
            'revoke_reason' => 'Interrupted',
            'status' => RefundRevocationAttemptStatus::Pending,
            'actor_user_id' => $ops->id,
        ]);

        $notice = app(DeskFirstWalletRefundRecoveryInspector::class)->revocationRecoveryNotice($refund);
        $this->assertNotNull($notice);
        $this->assertStringContainsString('Revoke again', $notice);

        $debitsBefore = CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->count();
        $this->actingAs($ops)->post(route('refunds.revoke', $refund), [
            'customer_outcome' => 'wants_service',
            'revoke_reason' => 'Interrupted',
        ])->assertSessionHas('status', 'refund-revoked');

        $this->assertSame($debitsBefore, CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->count());
    }

    /** Scenario E: never mark completed when spoke permanently fails. */
    public function test_scenario_e_permanent_spoke_failure_never_completes_refund(): void
    {
        Http::fake([
            self::SPOKE_URL => Http::response(['message' => 'Order not found'], 404),
        ]);

        [$ops, $refund] = $this->pendingWalletRefund('RD90404', '20.00', 'REF-FI-E');

        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($ops)->post(route('refunds.complete', $refund), [])->assertSessionHasErrors('refund');
        }

        $refund->refresh();
        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        $this->assertNull($refund->executed_at);
        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', 'REF-FI-E')->count());
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
            'customer_email' => 'failure-inject@example.com',
            'created_by' => $ops->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-'.substr($reference, -6),
            'category' => 'Refund',
            'source' => 'internal',
            'title' => 'Failure injection',
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
            'reason' => 'Failure injection',
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
