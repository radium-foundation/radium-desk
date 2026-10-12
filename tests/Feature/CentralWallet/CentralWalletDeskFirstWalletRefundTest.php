<?php

namespace Tests\Feature\CentralWallet;

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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralWalletDeskFirstWalletRefundTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_desk_first_creates_one_ledger_credit_and_calls_spoke_audit(): void
    {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'message' => 'Central Wallet audit recorded',
                'data' => [
                    'wallet_transaction_id' => 99,
                    'wallet_reference' => 'CW:99',
                    'desk_refund_reference' => 'REF-DF-001',
                    'credit' => '100.00',
                    'currency' => 'INR',
                    'balance' => '0.00',
                    'destination' => 'central_wallet',
                ],
            ], 201),
        ]);

        [$ops, $refund] = $this->pendingWalletRefund('RD90001', '100.00', 'REF-DF-001');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-completed');

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('business_reference', 'REF-DF-001')->count());
        $entry = CentralWalletLedgerEntry::query()->where('business_reference', 'REF-DF-001')->firstOrFail();
        $this->assertSame(LedgerEntryType::Credit, $entry->entry_type);

        $refund->refresh();
        $this->assertSame('CW:'.$entry->id, $refund->execution_reference_no);
        $this->assertSame((string) $entry->id, $refund->execution_transaction_id);

        Http::assertSent(function ($request) use ($entry) {
            return $request->url() === 'https://rdservice.in.test/api/integrations/v1/wallet-refunds'
                && ($request['central_wallet_ledger_entry_id'] ?? null) === $entry->id;
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
            'customer_email' => 'desk-first@example.com',
            'created_by' => $ops->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-'.substr($reference, -6),
            'category' => 'Refund',
            'source' => 'internal',
            'title' => 'Desk-first wallet refund test',
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
            'reason' => 'Desk-first test',
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
