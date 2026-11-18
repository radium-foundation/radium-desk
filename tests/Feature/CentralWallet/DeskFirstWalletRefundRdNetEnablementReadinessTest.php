<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RdService\RdServiceNetWalletRefundClient;
use App\Support\Refunds\DeskFirstWalletRefundSpokePolicy;
use App\Services\Refunds\WalletRefundDestinationResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P-18-34: RN-style merchant refunds via Desk-first + rdservice.net audit contract (rdservice.net @ 336610cc).
 */
class DeskFirstWalletRefundRdNetEnablementReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'rdservice_net.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.rdservice_net.enabled' => true,
            'order_lookup.spokes.rdservice_net.base_url' => 'https://rdservice.net.test',
            'order_lookup.spokes.rdservice_net.token' => 'rdnet-desk-token',
        ]);
    }

    public function test_mainline_defaults_block_rn_desk_first(): void
    {
        config([
            'central_wallet.wallet_refund.desk_first_enabled' => false,
            'central_wallet.wallet_refund.desk_first_spoke_allowlist' => 'rdservice.in',
        ]);

        $resolver = app(WalletRefundDestinationResolver::class);
        $this->assertFalse(DeskFirstWalletRefundSpokePolicy::isSpokeAllowedForDeskFirst('RN8201', $resolver));

        [$ops, $refund] = $this->pendingWalletRefund('RN8201', '599.00', 'REF-P1834-OFF');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertSessionHasErrors('refund');

        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
    }

    public function test_desk_first_on_without_rdnet_allowlist_blocks_rn_order(): void
    {
        config([
            'central_wallet.wallet_refund.desk_first_enabled' => true,
            'central_wallet.wallet_refund.desk_first_spoke_allowlist' => 'rdservice.in',
        ]);

        [$ops, $refund] = $this->pendingWalletRefund('RN8202', '979.00', 'REF-P1834-NO-ALLOW');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertSessionHasErrors('refund');

        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
        Http::assertNothingSent();
    }

    #[DataProvider('merchantFullRefundAmountProvider')]
    public function test_rdnet_allowlisted_desk_first_sends_spoke_audit_contract(
        string $orderId,
        string $amount,
        string $reference,
    ): void {
        config([
            'central_wallet.wallet_refund.desk_first_enabled' => true,
            'central_wallet.wallet_refund.desk_first_spoke_allowlist' => 'rdservice.in,rdservice.net',
        ]);

        Http::fake([
            'https://rdservice.net.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'message' => 'Central Wallet audit recorded',
                'data' => [
                    'wallet_transaction_id' => 501,
                    'wallet_reference' => 'CW:501',
                    'desk_refund_reference' => $reference,
                    'credit' => $amount,
                    'currency' => 'INR',
                    'balance' => '0.00',
                    'destination' => 'central_wallet',
                ],
            ], 201),
        ]);

        [$ops, $refund] = $this->pendingWalletRefund($orderId, $amount, $reference);

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertSessionHas('status', 'refund-completed');

        $entry = CentralWalletLedgerEntry::query()->where('business_reference', $reference)->firstOrFail();

        Http::assertSent(function ($request) use ($entry, $orderId, $amount, $reference) {
            $body = $request->data();

            return $request->url() === 'https://rdservice.net.test/api/integrations/v1/wallet-refunds'
                && ($body['source_system'] ?? null) === RdServiceNetWalletRefundClient::SOURCE_SYSTEM
                && ($body['desk_refund_reference'] ?? null) === $reference
                && ($body['order_id'] ?? null) === $orderId
                && ($body['amount'] ?? null) === $amount
                && ($body['currency'] ?? null) === 'INR'
                && ($body['central_wallet_ledger_entry_id'] ?? null) === $entry->id;
        });

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', $reference)->count());
    }

    /** @return list<array{string, string, string}> */
    public static function merchantFullRefundAmountProvider(): array
    {
        return [
            'p1834_amount_599' => ['RN8203', '599.00', 'REF-P1834-599'],
            'p1834_amount_979' => ['RN8204', '979.00', 'REF-P1834-979'],
        ];
    }

    public function test_rdnet_client_rejects_non_positive_ledger_id_without_posting(): void
    {
        config([
            'central_wallet.wallet_refund.desk_first_enabled' => true,
            'central_wallet.wallet_refund.desk_first_spoke_allowlist' => 'rdservice.net',
        ]);

        Http::fake();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(RdServiceNetWalletRefundClient::class)->creditWalletRefund(
            deskRefundReference: 'REF-P1834-BAD',
            orderId: 'RN8205',
            amount: '10.00',
            centralWalletLedgerEntryId: 0,
        );
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
            'serial_number' => 'SN',
            'product_name' => 'Device',
            'device_model' => 'X',
            'status' => 'active',
            'payment_amount' => $amount,
            'customer_email' => 'rdnet-readiness@example.com',
            'created_by' => $ops->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-'.substr($reference, -4),
            'category' => 'Refund',
            'source' => 'internal',
            'title' => 'P-18-34 RDNET readiness',
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
            'reason' => 'test',
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