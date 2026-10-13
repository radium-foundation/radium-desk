<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundRevokeCustomerOutcome;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RdService\RdServiceInWalletRefundReversalClient;
use App\Services\Refunds\DeskFirstWalletRefundOrchestrator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeskFirstWalletRefundRecoveryTest extends TestCase
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
            'rdservice_in.wallet_refund_reversal_enabled' => true,
            'commercial_state.enabled' => true,
        ]);
    }

    public function test_spoke_timeout_via_complete_keeps_committed_desk_credit_and_stays_pending(): void
    {
        $spokeAttempts = 0;
        Http::fake(function () use (&$spokeAttempts): never {
            $spokeAttempts++;

            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        [$ops, $refund] = $this->pendingWalletRefund('RD90101', '150.00', 'REF-REC-A');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertSessionHasErrors('refund');

        $refund->refresh();
        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', 'REF-REC-A')->count());
        $this->assertSame(1, $spokeAttempts);
    }

    public function test_retry_after_spoke_timeout_completes_with_single_desk_ledger_credit(): void
    {
        $spokeAttempts = 0;
        Http::fake(function () use (&$spokeAttempts) {
            $spokeAttempts++;
            if ($spokeAttempts === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response([
                'status' => 201,
                'message' => 'Central Wallet audit recorded',
                'data' => [
                    'wallet_transaction_id' => 501,
                    'wallet_reference' => 'CW:501',
                    'desk_refund_reference' => 'REF-REC-B',
                    'credit' => '200.00',
                    'currency' => 'INR',
                    'balance' => '0.00',
                    'destination' => 'central_wallet',
                ],
            ], 201);
        });

        [$ops, $refund] = $this->pendingWalletRefund('RD90102', '200.00', 'REF-REC-B');

        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])
            ->assertSessionHasErrors('refund');

        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-completed');

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', 'REF-REC-B')->count());
        $entry = CentralWalletLedgerEntry::query()->where('business_reference', 'REF-REC-B')->firstOrFail();
        $refund->refresh();
        $this->assertSame('CW:'.$entry->id, $refund->execution_reference_no);
        $this->assertSame(2, $spokeAttempts);
    }

    public function test_orchestrator_persists_desk_credit_and_reuses_it_when_spoke_fails_then_retries(): void
    {
        $spokeAttempts = 0;
        Http::fake(function () use (&$spokeAttempts) {
            $spokeAttempts++;
            if ($spokeAttempts === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 88,
                    'wallet_reference' => 'CW:88',
                    'desk_refund_reference' => 'REF-REC-C',
                    'credit' => '99.00',
                    'currency' => 'INR',
                    'balance' => '0.00',
                ],
            ], 201);
        });

        [$ops, $refund] = $this->pendingWalletRefund('RD90103', '99.00', 'REF-REC-C');
        $orchestrator = app(DeskFirstWalletRefundOrchestrator::class);

        try {
            $orchestrator->execute($refund, $ops, [], 'RD90103', '99.00');
            $this->fail('Expected spoke timeout');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', 'REF-REC-C')->count());
        $entryId = (int) CentralWalletLedgerEntry::query()->where('business_reference', 'REF-REC-C')->value('id');

        $result = $orchestrator->execute($refund, $ops, [], 'RD90103', '99.00');
        $this->assertTrue($result['metadata']['desk_ledger_idempotent_replay'] ?? false);
        $this->assertSame($entryId, (int) $result['metadata']['central_wallet_ledger_entry_id']);
        $this->assertSame(2, $spokeAttempts);

        Http::assertSent(function ($request) use ($entryId) {
            return $request->url() === self::SPOKE_URL
                && ($request['central_wallet_ledger_entry_id'] ?? null) === $entryId;
        });
    }

    public function test_permanent_spoke_failure_does_not_mark_refund_completed(): void
    {
        Http::fake([
            self::SPOKE_URL => Http::response(['message' => 'Order not found'], 404),
        ]);

        [$ops, $refund] = $this->pendingWalletRefund('RD90104', '50.00', 'REF-REC-F');

        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])
            ->assertSessionHasErrors('refund');

        $refund->refresh();
        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        $this->assertNull($refund->executed_at);
    }

    public function test_repeated_spoke_failures_then_success_do_not_duplicate_desk_ledger(): void
    {
        $spokeAttempts = 0;
        Http::fake(function () use (&$spokeAttempts) {
            $spokeAttempts++;
            if ($spokeAttempts < 3) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 77,
                    'wallet_reference' => 'CW:77',
                    'desk_refund_reference' => 'REF-REC-E',
                    'credit' => '10.00',
                    'currency' => 'INR',
                    'balance' => '0.00',
                ],
            ], 201);
        });

        [$ops, $refund] = $this->pendingWalletRefund('RD90105', '10.00', 'REF-REC-E');

        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($ops)->post(route('refunds.complete', $refund), [])
                ->assertSessionHasErrors('refund');
            $refund->refresh();
            $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        }

        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])
            ->assertSessionHas('status', 'refund-completed');

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', 'REF-REC-E')->count());
        $this->assertSame(3, $spokeAttempts);
    }

    public function test_desk_first_revoke_debits_central_wallet_and_retires_spoke_audit(): void
    {
        $reversalUrl = 'https://rdservice.in.test'.RdServiceInWalletRefundReversalClient::REVERSAL_PATH;

        Http::fake([
            self::SPOKE_URL => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 66,
                    'wallet_reference' => 'CW:66',
                    'desk_refund_reference' => 'REF-REC-G',
                    'credit' => '25.00',
                    'currency' => 'INR',
                    'balance' => '0.00',
                ],
            ], 201),
            $reversalUrl => Http::response([
                'status' => 201,
                'message' => 'Central Wallet audit retired',
                'data' => [
                    'wallet_reversal_transaction_id' => 9001,
                    'wallet_reversal_reference' => '9001',
                    'desk_refund_reference' => 'REF-REC-G',
                    'debit' => '0.00',
                    'balance' => '0.00',
                    'destination' => 'central_wallet',
                ],
            ], 201),
        ]);

        [$ops, $refund] = $this->pendingWalletRefund('RD90106', '25.00', 'REF-REC-G');

        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])
            ->assertSessionHas('status', 'refund-completed');

        $entry = CentralWalletLedgerEntry::query()->where('business_reference', 'REF-REC-G')->firstOrFail();

        $this->actingAs($ops)->post(route('refunds.revoke', $refund), [
            'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
            'revoke_reason' => 'Customer wants service instead of wallet refund.',
        ])->assertSessionHas('status', 'refund-revoked');

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', 'debit')->count());

        Http::assertSent(function ($request) use ($entry, $reversalUrl) {
            return $request->url() === $reversalUrl
                && ($request['central_wallet_authoritative'] ?? false) === true
                && (string) ($request['original_wallet_transaction_id'] ?? '') === (string) $entry->id;
        });
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
            'customer_email' => 'desk-first-recovery@example.com',
            'created_by' => $ops->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-'.substr($reference, -6),
            'category' => 'Refund',
            'source' => 'internal',
            'title' => 'Desk-first recovery test',
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
            'reason' => 'Desk-first recovery test',
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
