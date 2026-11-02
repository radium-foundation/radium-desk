<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\WalletRefundAuthoritativeCreditGuard;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Refunds\DeskFirstWalletRefundOrchestrator;
use App\Support\Refunds\RadiumBoxWalletRefundSpokeOrderResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Production-shaped desk-first cutover contract for REF-67458 / RB1321 / userid 551444.
 *
 * Lookup fixture captured from production on 2026-11-02 (orders_id null, rd_order.userid 551444).
 */
class DeskFirstWalletRefundRef67458RadiumBoxCutoverTest extends TestCase
{
    use RefreshDatabase;

    private const REFERENCE = 'REF-67458';

    private const ORDER_ID = 'RB1321';

    private const AMOUNT = '717.00';

    private const BOX_USER_ID = 551444;

    private const LOOKUP_URL = 'https://radiumbox.test/api/integrations/v1/rd-orders/RB1321';

    private const WALLET_REFUNDS_URL = 'https://radiumbox.test/api/integrations/v1/wallet-refunds';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'central_wallet.wallet_refund.desk_first_enabled' => true,
            'central_wallet.wallet_refund.desk_authoritative_enabled' => true,
            'central_wallet.wallet_refund.desk_first_spoke_allowlist' => 'rdservice.in,radiumbox.com',
            'radiumbox.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'lookup-token',
            'order_lookup.spokes.radiumbox_com.accepts' => ['rd', 'rde', 'rb', 'rbp', 'rbx'],
        ]);
    }

    public function test_prod_shaped_lookup_keeps_rb1321_as_spoke_order_id(): void
    {
        Http::fake([
            self::LOOKUP_URL => Http::response($this->productionLookupPayload(), 200),
        ]);

        $resolved = app(RadiumBoxWalletRefundSpokeOrderResolver::class)->resolveWalletOrderId(self::ORDER_ID);

        $this->assertSame(self::ORDER_ID, $resolved);
    }

    public function test_ref_67458_completes_with_one_desk_credit_and_box_audit_contract(): void
    {
        Http::fake([
            self::LOOKUP_URL => Http::response($this->productionLookupPayload(), 200),
            self::WALLET_REFUNDS_URL => Http::response([
                'status' => 201,
                'message' => 'Central Wallet audit recorded',
                'data' => [
                    'wallet_transaction_id' => 9001,
                    'wallet_reference' => 'CW:9001',
                    'desk_refund_reference' => self::REFERENCE,
                    'source_system' => 'radium_desk',
                    'userid' => self::BOX_USER_ID,
                    'credit' => self::AMOUNT,
                    'currency' => 'INR',
                    'balance' => '0.00',
                    'destination' => 'central_wallet',
                    'local_spendable_balance' => '0.00',
                ],
            ], 201),
        ]);

        [$ops, $refund] = $this->ref67458PendingWalletRefund();

        $guard = app(WalletRefundAuthoritativeCreditGuard::class);
        $this->assertSame([], $guard->authorityGapsForRefund($refund));
        $guard->assertMayPostStandaloneDeskCredit($refund);

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-completed');

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', self::REFERENCE)->count());
        $entry = CentralWalletLedgerEntry::query()->where('business_reference', self::REFERENCE)->firstOrFail();

        $refund->refresh();
        $this->assertSame(RefundStatus::Closed, $refund->status);
        $this->assertSame('CW:'.$entry->id, $refund->execution_reference_no);
        $this->assertSame((string) $entry->id, (string) $refund->execution_transaction_id);

        Http::assertSent(function ($request) use ($entry) {
            if ($request->url() !== self::WALLET_REFUNDS_URL) {
                return false;
            }

            return ($request['desk_refund_reference'] ?? null) === self::REFERENCE
                && ($request['order_id'] ?? null) === self::ORDER_ID
                && (float) ($request['amount'] ?? 0) === 717.0
                && ($request['currency'] ?? null) === 'INR'
                && ($request['central_wallet_ledger_entry_id'] ?? null) === $entry->id
                && ($request['source_system'] ?? null) === 'radium_desk';
        });
    }

    public function test_ref_67458_partial_failure_then_retry_is_idempotent_on_desk_ledger(): void
    {
        $spokeAttempts = 0;
        Http::fake(function ($request) use (&$spokeAttempts) {
            if ($request->url() === self::LOOKUP_URL) {
                return Http::response($this->productionLookupPayload(), 200);
            }

            if ($request->url() !== self::WALLET_REFUNDS_URL) {
                return Http::response([], 404);
            }

            $spokeAttempts++;
            if ($spokeAttempts === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 9002,
                    'wallet_reference' => 'CW:9002',
                    'desk_refund_reference' => self::REFERENCE,
                    'credit' => self::AMOUNT,
                    'currency' => 'INR',
                    'balance' => '0.00',
                ],
            ], 201);
        });

        [$ops, $refund] = $this->ref67458PendingWalletRefund();
        $orchestrator = app(DeskFirstWalletRefundOrchestrator::class);

        try {
            $orchestrator->execute($refund, $ops, [], self::ORDER_ID, self::AMOUNT);
            $this->fail('Expected spoke timeout on first attempt');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', self::REFERENCE)->count());
        $entryId = (int) CentralWalletLedgerEntry::query()->where('business_reference', self::REFERENCE)->value('id');

        $result = $orchestrator->execute($refund, $ops, [], self::ORDER_ID, self::AMOUNT);
        $this->assertTrue($result['metadata']['desk_ledger_idempotent_replay'] ?? false);
        $this->assertSame($entryId, (int) ($result['metadata']['central_wallet_ledger_entry_id'] ?? 0));
        $this->assertSame(2, $spokeAttempts);
    }

    public function test_ref_67458_box_spoke_failure_via_complete_keeps_pending_with_single_desk_credit(): void
    {
        Http::fake([
            self::LOOKUP_URL => Http::response($this->productionLookupPayload(), 200),
            self::WALLET_REFUNDS_URL => Http::response(['message' => 'upstream error'], 502),
        ]);

        [$ops, $refund] = $this->ref67458PendingWalletRefund();

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertSessionHasErrors('refund');

        $refund->refresh();
        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', self::REFERENCE)->count());
        $this->assertNull($refund->execution_reference_no);
    }

    /**
     * @return array<string, mixed>
     */
    private function productionLookupPayload(): array
    {
        $path = base_path('tests/fixtures/radiumbox-order-lookup-rb1321-ref67458.json');
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array{0: User, 1: RefundRequest}
     */
    private function ref67458PendingWalletRefund(): array
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
            'order_id' => self::ORDER_ID,
            'customer_id' => $customerId,
            'serial_number' => 'SN-RB1321',
            'product_name' => 'MSO1300 E3-L1 RD',
            'device_model' => 'MSO1300 E3-L1 RD',
            'status' => 'active',
            'payment_amount' => self::AMOUNT,
            'customer_email' => 'vermapiyush360@gmail.com',
            'created_by' => $ops->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-67458',
            'category' => 'Refund',
            'source' => 'internal',
            'title' => 'REF-67458 cutover readiness',
            'description' => 'P-02-45 fixture',
            'status' => 'open',
            'created_by' => $ops->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => self::REFERENCE,
            'amount' => self::AMOUNT,
            'refund_amount' => self::AMOUNT,
            'reason' => 'Wallet refund RB1321',
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
