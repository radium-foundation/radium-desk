<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeskFirstWalletRefundDurableExecutionTest extends TestCase
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

    public function test_retry_after_timeout_reuses_same_ledger_entry_id_on_spoke(): void
    {
        $spokeAttempts = 0;
        Http::fake(function () use (&$spokeAttempts) {
            $spokeAttempts++;
            if ($spokeAttempts === 1) {
                throw new ConnectionException('timeout');
            }

            return Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 1,
                    'wallet_reference' => 'CW:1',
                    'desk_refund_reference' => 'REF-DUR-1',
                    'credit' => '80.00',
                    'currency' => 'INR',
                    'balance' => '0.00',
                ],
            ], 201);
        });

        [$ops, $refund] = $this->pendingWalletRefund('RD90201', '80.00', 'REF-DUR-1');

        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])->assertSessionHasErrors('refund');
        $entryId = (int) CentralWalletLedgerEntry::query()->where('business_reference', 'REF-DUR-1')->value('id');

        $this->actingAs($ops)->post(route('refunds.complete', $refund), [])->assertSessionHas('status', 'refund-completed');

        Http::assertSent(function ($request) use ($entryId) {
            return $request->url() === self::SPOKE_URL
                && ($request['central_wallet_ledger_entry_id'] ?? null) === $entryId;
        });
        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', 'REF-DUR-1')->count());
    }

    public function test_concurrent_complete_attempts_create_one_credit(): void
    {
        Http::fake([
            self::SPOKE_URL => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 2,
                    'wallet_reference' => 'CW:2',
                    'desk_refund_reference' => 'REF-DUR-2',
                    'credit' => '40.00',
                    'currency' => 'INR',
                    'balance' => '0.00',
                ],
            ], 201),
        ]);

        [$ops, $refund] = $this->pendingWalletRefund('RD90202', '40.00', 'REF-DUR-2');

        $this->actingAs($ops)->post(route('refunds.complete', $refund), []);
        $this->actingAs($ops)->post(route('refunds.complete', $refund), []);

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', 'REF-DUR-2')->count());
        $refund->refresh();
        $this->assertTrue($refund->status?->isEligibleForRevoke() ?? false);
    }

    public function test_insufficient_balance_blocks_desk_first_revoke_debit(): void
    {
        Http::fake([
            self::SPOKE_URL => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 3,
                    'wallet_reference' => 'CW:3',
                    'desk_refund_reference' => 'REF-DUR-3',
                    'credit' => '500.00',
                    'currency' => 'INR',
                    'balance' => '0.00',
                ],
            ], 201),
        ]);

        [$ops, $refund, $cwid] = $this->pendingWalletRefund('RD90203', '500.00', 'REF-DUR-3');

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

        config(['rdservice_in.wallet_refund_reversal_enabled' => true, 'commercial_state.enabled' => true]);

        $debitCountBeforeRevoke = CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->count();

        $this->actingAs($ops)->post(route('refunds.revoke', $refund), [
            'customer_outcome' => 'wants_service',
            'revoke_reason' => 'Customer wants service.',
        ])->assertSessionHasErrors('refund');

        $this->assertSame(
            $debitCountBeforeRevoke,
            CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->count(),
        );
        $this->assertTrue($refund->fresh()->status?->isEligibleForRevoke() ?? false);
    }

    /**
     * @return array{0: User, 1: RefundRequest, 2: string}
     */
    private function pendingWalletRefund(
        string $orderId,
        string $amount,
        string $reference,
    ): array {
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
            'customer_email' => 'durable@example.com',
            'created_by' => $ops->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-'.substr($reference, -6),
            'category' => 'Refund',
            'source' => 'internal',
            'title' => 'Durable desk-first test',
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
            'reason' => 'Durable test',
            'status' => RefundStatus::PendingExecution,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $ops->id,
            'reviewed_by' => $ops->id,
            'reviewed_at' => now(),
            'communication_channels' => [],
        ]);

        return [$ops, $refund, $cwid];
    }
}
