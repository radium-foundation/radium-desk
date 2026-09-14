<?php

namespace Tests\Feature\RadiumBox;

use App\Enums\OrderStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Events\Finance\OrderPaid;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\RadiumBox\RadiumBoxPaymentConfirmationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RadiumBoxPaymentConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'radiumbox.payment_confirm.enabled' => true,
            'radiumbox.handoff_reconciliation.enabled' => true,
            'radiumbox.handoff_reconciliation.sla_minutes' => 15,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'desk-token',
        ]);
    }

    public function test_order_paid_listener_confirms_box_payment_for_rbp_hardware(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/cashfree/confirm-payment' => Http::response([
                'status' => 'paid',
                'first_paid' => true,
                'gateway_order_id' => 'RBP94',
                'business_order_id' => 'RBP94',
                'payment_status' => 'Paid',
                'handoff' => [
                    'id' => 1,
                    'status' => 'pending',
                    'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RBP94',
                ],
            ], 200),
        ]);

        $order = $this->createPaidHardwareOrder('RBP94');

        OrderPaid::dispatch($order);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://radiumbox.test/api/integrations/v1/cashfree/confirm-payment'
                && ($request['gateway_order_id'] ?? null) === 'RBP94'
                && ($request['payment_id'] ?? null) === 'pay_94';
        });

        $this->assertSame(
            RadiumBoxEnrichmentSyncStatus::HandoffPending,
            $order->fresh()->radiumbox_sync_status,
        );
    }

    public function test_duplicate_box_confirm_is_idempotent(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/cashfree/confirm-payment' => Http::sequence()
                ->push([
                    'status' => 'paid',
                    'first_paid' => true,
                    'gateway_order_id' => 'RBP95',
                    'business_order_id' => 'RBP95',
                    'payment_status' => 'Paid',
                    'handoff' => [
                        'id' => 2,
                        'status' => 'pending',
                        'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RBP95',
                    ],
                ], 200)
                ->push([
                    'status' => 'already_paid',
                    'first_paid' => false,
                    'gateway_order_id' => 'RBP95',
                    'business_order_id' => 'RBP95',
                    'payment_status' => 'Paid',
                    'handoff' => [
                        'id' => 2,
                        'status' => 'pending',
                        'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RBP95',
                    ],
                ], 200),
        ]);

        $order = $this->createPaidHardwareOrder('RBP95');
        $service = app(RadiumBoxPaymentConfirmationService::class);

        $first = $service->confirmForOrder($order);
        $second = $service->confirmForOrder($order->fresh());

        $this->assertTrue($first->ok);
        $this->assertTrue($second->ok);
        $this->assertSame('paid', $first->status);
        $this->assertSame('already_paid', $second->status);
    }

    public function test_reconcile_handoff_recovers_stale_paid_hardware_without_commerce(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/cashfree/confirm-payment' => Http::response([
                'status' => 'paid',
                'first_paid' => true,
                'gateway_order_id' => 'RBP96',
                'business_order_id' => 'RBP96',
                'payment_status' => 'Paid',
                'handoff' => [
                    'id' => 3,
                    'status' => 'pending',
                    'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RBP96',
                ],
            ], 200),
        ]);

        $order = $this->createPaidHardwareOrder('RBP96');
        $createdAt = now()->subMinutes(30);
        $order->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        Artisan::call('radiumbox:reconcile-handoff');

        Http::assertSentCount(1);
        $this->assertSame(
            RadiumBoxEnrichmentSyncStatus::HandoffPending,
            $order->fresh()->radiumbox_sync_status,
        );
    }

    public function test_enrichment_complete_does_not_false_green_sync_for_hardware_without_commerce(): void
    {
        $order = $this->createPaidHardwareOrder('RBP97');
        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);

        $syncStore->markEnrichmentComplete($order->id, $order, ['lookup_result' => 'test']);

        $this->assertSame(
            RadiumBoxEnrichmentSyncStatus::HandoffPending,
            $order->fresh()->radiumbox_sync_status,
        );
    }

    public function test_confirm_uses_business_order_id_when_desk_gateway_order_id_is_numeric(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/cashfree/confirm-payment' => Http::response([
                'status' => 'paid',
                'first_paid' => true,
                'gateway_order_id' => 'RBP94',
                'business_order_id' => 'RBP94',
                'payment_status' => 'Paid',
                'handoff' => [
                    'id' => 94,
                    'status' => 'pending',
                    'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RBP94',
                ],
            ], 200),
        ]);

        $order = $this->createPaidHardwareOrder('RBP94');
        $order->forceFill(['gateway_order_id' => '6893492675'])->saveQuietly();

        app(RadiumBoxPaymentConfirmationService::class)->confirmForOrder($order);

        Http::assertSent(function ($request): bool {
            return ($request['gateway_order_id'] ?? null) === 'RBP94'
                && ($request['payment_id'] ?? null) === 'pay_94';
        });
    }

    public function test_targeted_reconcile_recovers_business_order_id(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/cashfree/confirm-payment' => Http::response([
                'status' => 'paid',
                'first_paid' => true,
                'gateway_order_id' => 'RBP94',
                'business_order_id' => 'RBP94',
                'payment_status' => 'Paid',
                'handoff' => [
                    'id' => 94,
                    'status' => 'pending',
                    'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RBP94',
                ],
            ], 200),
        ]);

        $order = $this->createPaidHardwareOrder('RBP94');

        $exitCode = Artisan::call('radiumbox:reconcile-handoff', [
            '--order-id' => 'RBP94',
        ]);

        $this->assertSame(0, $exitCode);
        Http::assertSentCount(1);
        $this->assertSame(
            RadiumBoxEnrichmentSyncStatus::HandoffPending,
            $order->fresh()->radiumbox_sync_status,
        );
    }

    public function test_cashfree_not_paid_marks_reconciliation_required(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/cashfree/confirm-payment' => Http::response([
                'status' => 'not_paid',
                'message' => 'Cashfree order is not paid yet.',
            ], 503),
        ]);

        $order = $this->createPaidHardwareOrder('RBP99');
        $result = app(RadiumBoxPaymentConfirmationService::class)->confirmForOrder($order);

        $this->assertFalse($result->ok);
        $this->assertTrue($result->retriable);
        $this->assertSame(
            RadiumBoxEnrichmentSyncStatus::ReconciliationRequired,
            $order->fresh()->radiumbox_sync_status,
        );
    }

    private function createPaidHardwareOrder(string $orderId): Order
    {
        $user = User::factory()->create();

        return Order::query()->create([
            'order_id' => $orderId,
            'cashfree_payment_id' => 'pay_'.substr($orderId, 3),
            'gateway_order_id' => $orderId,
            'payment_amount' => 4998,
            'payment_method' => 'cashfree',
            'payment_date' => now(),
            'status' => OrderStatus::Active,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }
}
