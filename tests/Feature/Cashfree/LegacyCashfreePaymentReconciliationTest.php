<?php

namespace Tests\Feature\Cashfree;

use App\Enums\LegacyCashfreePaymentReconciliationState;
use App\Enums\OrderStatus;
use App\Models\LegacyCashfreePaymentReconciliation;
use App\Models\Order;
use App\Models\User;
use App\Services\Cashfree\LegacyCashfreePaymentReconciliationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LegacyCashfreePaymentReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'cashfree.legacy_payment_reconciliation.enabled' => true,
            'cashfree.legacy_payment_reconciliation.execution_enabled' => false,
            'cashfree.api.app_id' => 'test-app',
            'cashfree.api.secret' => 'test-secret',
            'cashfree.api.base_url' => 'https://api.cashfree.test/pg',
            'cashfree.api.version' => '2026-01-01',
        ]);
    }

    public function test_legacy_order_without_payment_becomes_reconciliation_candidate(): void
    {
        $user = User::factory()->create();
        $order = $this->legacyOrder($user);

        $rec = app(LegacyCashfreePaymentReconciliationService::class)->enqueueForImportedLegacyOrder($order);

        $this->assertNotNull($rec);
        $this->assertSame(LegacyCashfreePaymentReconciliationState::Pending, $rec->state);
        $this->assertSame($order->id, $rec->order_id);
    }

    public function test_native_order_is_not_enqueued(): void
    {
        $user = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD9990001',
            'customer_name' => 'Native',
            'status' => OrderStatus::Active,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $rec = app(LegacyCashfreePaymentReconciliationService::class)->enqueueForImportedLegacyOrder($order);

        $this->assertNull($rec);
    }

    public function test_dry_run_safe_to_link_classifies_as_verified_payment_without_desk_mutation(): void
    {
        $user = User::factory()->create();
        $order = $this->legacyOrder($user);

        $rec = LegacyCashfreePaymentReconciliation::query()->create([
            'order_id' => $order->id,
            'business_order_id' => $order->order_id,
            'legacy_source' => 'radiumbox',
            'state' => LegacyCashfreePaymentReconciliationState::Pending,
        ]);

        $this->fakeCashfreePaidOrder($order->order_id, '5693263067', 599);

        $updated = app(LegacyCashfreePaymentReconciliationService::class)->process($rec, dryRun: true);

        $this->assertSame(LegacyCashfreePaymentReconciliationState::VerifiedPayment, $updated->state);
        $order->refresh();
        $this->assertNull($order->payment_amount);
        $this->assertNull($order->cashfree_payment_id);
    }

    public function test_refund_lookup_failure_holds_in_refund_state_unknown(): void
    {
        $user = User::factory()->create();
        $order = $this->legacyOrder($user);

        $rec = LegacyCashfreePaymentReconciliation::query()->create([
            'order_id' => $order->id,
            'business_order_id' => $order->order_id,
            'legacy_source' => 'radiumbox',
            'state' => LegacyCashfreePaymentReconciliationState::Pending,
        ]);

        Http::fake([
            'https://api.cashfree.test/pg/orders/'.$order->order_id => Http::response([
                'order_id' => $order->order_id,
                'order_status' => 'PAID',
                'order_amount' => 599,
                'order_currency' => 'INR',
                'customer_details' => [
                    'customer_email' => $order->customer_email,
                    'customer_phone' => $order->customer_phone,
                ],
            ]),
            'https://api.cashfree.test/pg/orders/'.$order->order_id.'/payments' => Http::response([
                'payments' => [[
                    'cf_payment_id' => '5693263067',
                    'payment_status' => 'SUCCESS',
                    'payment_amount' => 599,
                    'payment_currency' => 'INR',
                ]],
            ]),
            'https://api.cashfree.test/pg/orders/'.$order->order_id.'/refunds' => Http::response([], 429),
        ]);

        $updated = app(LegacyCashfreePaymentReconciliationService::class)->process($rec, dryRun: true);

        $this->assertSame(LegacyCashfreePaymentReconciliationState::RefundStateUnknown, $updated->state);
    }

    public function test_command_does_not_touch_legacy_orders_without_reconciliation_row(): void
    {
        $user = User::factory()->create();
        $order = $this->legacyOrder($user);

        $this->artisan('cashfree:reconcile-legacy-payments', ['--dry-run' => true])
            ->assertSuccessful();

        $order->refresh();
        $this->assertNull($order->payment_amount);
        $this->assertDatabaseCount('legacy_cashfree_payment_reconciliations', 0);
    }

    public function test_radiumbox_enrichment_does_not_write_payment_columns(): void
    {
        $this->assertContains('payment_amount', \App\Services\RadiumBox\RadiumBoxService::PAYMENT_COLUMNS);
        $this->assertContains('cashfree_payment_id', \App\Services\RadiumBox\RadiumBoxService::PAYMENT_COLUMNS);
    }

    private function legacyOrder(User $user): Order
    {
        return Order::query()->create([
            'order_id' => 'RD3402654',
            'customer_name' => 'Legacy Customer',
            'customer_email' => 'legacy@example.com',
            'customer_phone' => '9900000099',
            'legacy_source' => 'radiumbox',
            'legacy_imported_at' => now(),
            'legacy_imported_by_user_id' => $user->id,
            'status' => OrderStatus::Active,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    private function fakeCashfreePaidOrder(string $orderId, string $paymentId, float $amount): void
    {
        Http::fake([
            'https://api.cashfree.test/pg/orders/'.$orderId => Http::response([
                'order_id' => $orderId,
                'order_status' => 'PAID',
                'order_amount' => $amount,
                'order_currency' => 'INR',
                'customer_details' => [
                    'customer_email' => 'legacy@example.com',
                    'customer_phone' => '9900000099',
                ],
            ]),
            'https://api.cashfree.test/pg/orders/'.$orderId.'/payments' => Http::response([
                'payments' => [[
                    'cf_payment_id' => $paymentId,
                    'payment_status' => 'SUCCESS',
                    'payment_amount' => $amount,
                    'payment_currency' => 'INR',
                    'payment_group' => 'upi',
                ]],
            ]),
            'https://api.cashfree.test/pg/orders/'.$orderId.'/refunds' => Http::response([]),
        ]);
    }
}
