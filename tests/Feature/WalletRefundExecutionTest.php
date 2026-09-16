<?php

namespace Tests\Feature;

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
use Tests\TestCase;

class WalletRefundExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'rdservice_in.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.rdservice_in.enabled' => true,
            'order_lookup.spokes.rdservice_in.base_url' => 'https://rdservice.in.test',
            'order_lookup.spokes.rdservice_in.token' => 'desk-rdservice-wallet-token',
            'order_lookup.spokes.rdservice_in.connect_timeout_seconds' => 3,
            'order_lookup.spokes.rdservice_in.timeout_seconds' => 8,
            'radiumbox.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'desk-box-wallet-token',
            'order_lookup.spokes.radiumbox_com.connect_timeout_seconds' => 3,
            'order_lookup.spokes.radiumbox_com.timeout_seconds' => 8,
        ]);
    }

    public function test_rdservice_in_wallet_refund_posts_idempotent_credit(): void
    {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'message' => 'Wallet credit created',
                'data' => [
                    'wallet_transaction_id' => 91001,
                    'wallet_reference' => 'RD91001',
                    'source_system' => 'radium_desk',
                    'desk_refund_reference' => 'REF-2026-009920',
                    'userid' => 77,
                    'credit' => '499.00',
                    'currency' => 'INR',
                    'balance' => '499.00',
                ],
            ], 201),
        ]);

        [$ops, $refund] = $this->pendingWalletRefundFixture('RD3437801', '499.00', 'REF-2026-009920');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-completed');

        $refund->refresh();
        $this->assertContains($refund->status, [RefundStatus::Completed, RefundStatus::Closed]);
        $this->assertSame('RD91001', $refund->execution_reference_no);
        $this->assertSame('91001', $refund->execution_transaction_id);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://rdservice.in.test/api/integrations/v1/wallet-refunds'
                && $request['source_system'] === 'radium_desk'
                && $request['desk_refund_reference'] === 'REF-2026-009920'
                && $request['order_id'] === 'RD3437801'
                && $request['amount'] === '499.00'
                && $request['currency'] === 'INR'
                && $request['customer_email'] === 'customer@example.com';
        });
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'radiumbox.test'));
    }

    public function test_duplicate_rdservice_in_http_200_does_not_fail_completion(): void
    {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 200,
                'message' => 'Wallet credit already exists',
                'data' => [
                    'wallet_transaction_id' => 91002,
                    'wallet_reference' => 'RD91002',
                    'desk_refund_reference' => 'REF-2026-009921',
                    'credit' => '199.50',
                    'balance' => '199.50',
                ],
            ], 200),
        ]);

        [$ops, $refund] = $this->pendingWalletRefundFixture('RD3437802', '199.50', 'REF-2026-009921');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertRedirect(route('refunds.show', $refund));

        $refund->refresh();
        $this->assertSame('RD91002', $refund->execution_reference_no);
    }

    public function test_wallet_integration_unavailable_does_not_fall_back_to_manual(): void
    {
        config(['rdservice_in.wallet_refund_credit_enabled' => false]);
        Http::fake();

        [$ops, $refund] = $this->pendingWalletRefundFixture('RD3437803', '100.00', 'REF-2026-009922');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'MANUAL-SHOULD-NOT-COUNT',
            ])
            ->assertSessionHasErrors('refund');

        $refund->refresh();
        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        $this->assertNull($refund->executed_at);
        Http::assertNothingSent();
    }

    public function test_rdservice_in_api_timeout_leaves_refund_pending_execution(): void
    {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refunds' => function () {
                throw new ConnectionException('timed out');
            },
        ]);

        [$ops, $refund] = $this->pendingWalletRefundFixture('RD3437804', '100.00', 'REF-2026-009923');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertSessionHasErrors('refund');

        $refund->refresh();
        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
    }

    public function test_mapping_failure_from_storefront_fails_closed(): void
    {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 422,
                'message' => 'Customer account could not be resolved for this refund',
            ], 422),
        ]);

        [$ops, $refund] = $this->pendingWalletRefundFixture('RD3437805', '100.00', 'REF-2026-009924');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertSessionHasErrors('refund');

        $refund->refresh();
        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
    }

    public function test_amount_and_currency_rejection_fails_closed(): void
    {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 422,
                'message' => 'currency must be INR',
            ], 422),
        ]);

        [$ops, $refund] = $this->pendingWalletRefundFixture('RD3437806', '100.00', 'REF-2026-009925');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertSessionHasErrors('refund');

        $refund->refresh();
        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
    }

    public function test_radiumbox_wallet_refund_stays_isolated_on_box_orders(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'message' => 'Wallet credit created',
                'data' => [
                    'wallet_transaction_id' => 273105,
                    'wallet_reference' => 'RD273105',
                    'desk_refund_reference' => 'REF-2026-009926',
                    'userid' => 42,
                    'credit' => 617,
                    'balance' => 617,
                ],
            ], 201),
        ]);

        [$ops, $refund] = $this->pendingWalletRefundFixture('RDE318801', '617.00', 'REF-2026-009926');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertRedirect(route('refunds.show', $refund));

        $refund->refresh();
        $this->assertSame('RD273105', $refund->execution_reference_no);

        Http::assertSent(fn ($request) => $request->url() === 'https://radiumbox.test/api/integrations/v1/wallet-refunds'
            && $request['order_id'] === 'RDE318801');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'rdservice.in.test'));
    }

    public function test_unconfigured_radiumbox_wallet_does_not_fall_back_to_manual(): void
    {
        config(['radiumbox.wallet_refund_credit_enabled' => false]);
        Http::fake();

        [$ops, $refund] = $this->pendingWalletRefundFixture('RDE318802', '50.00', 'REF-2026-009927');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'BOX-MANUAL',
            ])
            ->assertSessionHasErrors('refund');

        $refund->refresh();
        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        Http::assertNothingSent();
    }

    public function test_explicit_manual_bank_transfer_still_completes(): void
    {
        Http::fake();

        $ops = User::factory()->create();
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
        $order = $this->createOrder($ops, 'RD3437807', '900.00');
        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-009928',
            'amount' => '900.00',
            'refund_amount' => '900.00',
            'reason' => 'Bank transfer refund should not hit wallet API.',
            'status' => RefundStatus::PendingExecution,
            'approved_refund_method' => ApprovedRefundMethod::BankTransfer,
            'requested_by' => $ops->id,
            'reviewed_by' => $ops->id,
            'reviewed_at' => now(),
            'communication_channels' => [],
        ]);

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-123',
            ])
            ->assertRedirect(route('refunds.show', $refund));

        Http::assertNothingSent();
        $refund->refresh();
        $this->assertSame('UTR-123', $refund->execution_reference_no);
    }

    public function test_already_closed_refund_cannot_be_recompleted(): void
    {
        Http::fake();

        [$ops, $refund] = $this->pendingWalletRefundFixture('RD3437808', '100.00', 'REF-2026-009929');
        $refund->update([
            'status' => RefundStatus::Closed,
            'executed_by' => $ops->id,
            'executed_at' => now(),
            'closed_at' => now(),
            'execution_reference_no' => 'REF-2026-009929',
        ]);

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertSessionHasErrors('refund');

        Http::assertNothingSent();
    }

    public function test_pending_approval_cannot_be_completed(): void
    {
        Http::fake();

        $ops = User::factory()->create();
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
        $order = $this->createOrder($ops, 'RD3437809', '400.00');
        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-009930',
            'amount' => '400.00',
            'refund_amount' => '400.00',
            'reason' => 'Pending approval should not credit wallet.',
            'status' => RefundStatus::Pending,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $ops->id,
            'communication_channels' => [],
        ]);

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertSessionHasErrors('refund');

        Http::assertNothingSent();
    }

    public function test_unknown_order_source_fails_closed(): void
    {
        Http::fake();

        [$ops, $refund] = $this->pendingWalletRefundFixture('ZZ999', '100.00', 'REF-2026-009931');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertSessionHasErrors('refund');

        $refund->refresh();
        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        Http::assertNothingSent();
    }

    /**
     * @return array{0: User, 1: RefundRequest}
     */
    private function pendingWalletRefundFixture(string $orderId, string $amount, string $reference): array
    {
        $ops = User::factory()->create();
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $order = $this->createOrder($ops, $orderId, $amount);
        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-'.substr($reference, -6),
            'category' => 'Refund',
            'source' => 'internal',
            'title' => 'Wallet refund execution test',
            'description' => 'Wallet refund execution test incident.',
            'status' => 'open',
            'created_by' => $ops->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => $reference,
            'amount' => $amount,
            'refund_amount' => $amount,
            'reason' => 'Wallet refund execution test.',
            'status' => RefundStatus::PendingExecution,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $ops->id,
            'reviewed_by' => $ops->id,
            'reviewed_at' => now(),
            'communication_channels' => [],
        ]);

        return [$ops, $refund];
    }

    private function createOrder(User $user, string $orderId, string $paymentAmount): Order
    {
        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'payment_amount' => $paymentAmount,
            'customer_email' => 'customer@example.com',
            'created_by' => $user->id,
        ]);
    }
}
