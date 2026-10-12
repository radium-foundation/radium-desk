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
use App\Services\RadiumBox\RadiumBoxWalletRefundClient;
use App\Services\Refunds\WalletRefundDestinationResolver;
use App\Support\Refunds\DeskFirstWalletRefundSpokePolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeskFirstWalletRefundMultiSpokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'central_wallet.wallet_refund.desk_first_enabled' => true,
            'central_wallet.wallet_refund.desk_first_spoke_allowlist' => 'rdservice.in,rdservice.net,radiumbox.com',
            'rdservice_in.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.rdservice_in.enabled' => true,
            'order_lookup.spokes.rdservice_in.base_url' => 'https://rdservice.in.test',
            'order_lookup.spokes.rdservice_in.token' => 'token',
            'rdservice_net.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.rdservice_net.enabled' => true,
            'order_lookup.spokes.rdservice_net.base_url' => 'https://rdservice.net.test',
            'order_lookup.spokes.rdservice_net.token' => 'token',
            'radiumbox.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'token',
        ]);
    }

    public function test_default_allowlist_blocks_radiumbox_orders(): void
    {
        config(['central_wallet.wallet_refund.desk_first_spoke_allowlist' => 'rdservice.in']);

        [$ops, $refund] = $this->pendingWalletRefund('RB900', '50.00', 'REF-MBOX-BLOCK');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertSessionHasErrors('refund');

        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
    }

    public function test_rdnet_desk_first_posts_one_desk_credit_and_sends_ledger_id_to_spoke(): void
    {
        Http::fake([
            'https://rdservice.net.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 5,
                    'wallet_reference' => 'CW:5',
                    'desk_refund_reference' => 'REF-MNET-1',
                    'credit' => '80.00',
                    'currency' => 'INR',
                    'balance' => '0.00',
                ],
            ], 201),
        ]);

        [$ops, $refund] = $this->pendingWalletRefund('RN90001', '80.00', 'REF-MNET-1');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertSessionHas('status', 'refund-completed');

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', 'REF-MNET-1')->count());
        $entry = CentralWalletLedgerEntry::query()->where('business_reference', 'REF-MNET-1')->firstOrFail();

        Http::assertSent(function ($request) use ($entry) {
            return $request->url() === 'https://rdservice.net.test/api/integrations/v1/wallet-refunds'
                && ($request['central_wallet_ledger_entry_id'] ?? null) === $entry->id;
        });
    }

    public function test_radiumbox_client_forwards_desk_ledger_id_when_allowlisted(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 99,
                    'wallet_reference' => 'CW:99',
                    'desk_refund_reference' => 'REF-MBOX-1',
                    'credit' => 40,
                    'balance' => 0,
                ],
            ], 201),
        ]);

        app(RadiumBoxWalletRefundClient::class)->creditWalletRefund(
            deskRefundReference: 'REF-MBOX-1',
            orderId: 'RB901',
            amount: 40.0,
            centralWalletLedgerEntryId: 12345,
        );

        Http::assertSent(function ($request) {
            return $request->url() === 'https://radiumbox.test/api/integrations/v1/wallet-refunds'
                && ($request['central_wallet_ledger_entry_id'] ?? null) === 12345
                && ($request['source_system'] ?? null) === 'radium_desk';
        });
    }

    public function test_spoke_policy_parses_allowlist(): void
    {
        config(['central_wallet.wallet_refund.desk_first_spoke_allowlist' => 'rdservice.in, radiumbox.com']);

        $resolver = app(WalletRefundDestinationResolver::class);

        $this->assertTrue(DeskFirstWalletRefundSpokePolicy::isSpokeAllowedForDeskFirst('RD1', $resolver));
        $this->assertTrue(DeskFirstWalletRefundSpokePolicy::isSpokeAllowedForDeskFirst('RB1', $resolver));
        $this->assertFalse(DeskFirstWalletRefundSpokePolicy::isSpokeAllowedForDeskFirst('RN1', $resolver));
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
            'customer_email' => 'multi-spoke@example.com',
            'created_by' => $ops->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-'.substr($reference, -4),
            'category' => 'Refund',
            'source' => 'internal',
            'title' => 'Multi-spoke desk-first',
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
