<?php

namespace Tests\Feature\RadiumBox;

use App\Enums\OrderStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\DeskAuthoritativeRadiumBoxPaymentGateway;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\RadiumBox\RadiumBoxPaymentConfirmationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RadiumBoxAuthoritativePaymentContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'radiumbox.payment_confirm.enabled' => true,
            'radiumbox.payment_confirm.contract' => DeskAuthoritativeRadiumBoxPaymentGateway::CONTRACT,
            'radiumbox.payment_confirm.authoritative.signing_secret' => 'test-signing-secret',
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'desk-token',
        ]);
    }

    public function test_desk_sends_authoritative_event_without_legacy_confirm_payment_path(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/desk/payment-events' => Http::response([
                'status' => 'accepted',
                'first_paid' => true,
                'gateway_order_id' => 'RBP2138',
                'business_order_id' => 'RBP2138',
                'payment_status' => 'Paid',
                'handoff' => [
                    'id' => 77,
                    'status' => 'pending',
                    'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RBP2138',
                ],
                'acknowledgement_id' => 'box-pay-ack:77',
            ], 200),
        ]);

        $order = $this->createPaidOrder('RBP2138', '6998469575', 2499);
        $result = app(RadiumBoxPaymentConfirmationService::class)->confirmForOrder($order);

        $this->assertTrue($result->ok);
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/desk/payment-events')) {
                return false;
            }

            return ($request->header('X-Desk-Idempotency-Key')[0] ?? '') === 'desk-payment:radiumbox_com:RBP2138:6998469575'
                && str_starts_with($request->header('X-Desk-Signature')[0] ?? '', 'sha256=')
                && ($request['payment']['cashfree_payment_id'] ?? null) === '6998469575'
                && ($request['payment']['amount'] ?? null) === '2499.00'
                && ($request['verification']['verified_by'] ?? null) === 'radium_desk';
        });
        Http::assertNotSent(function ($request): bool {
            return str_contains($request->url(), 'cashfree/confirm-payment');
        });

        $this->assertSame(
            RadiumBoxEnrichmentSyncStatus::HandoffPending,
            $order->fresh()->radiumbox_sync_status,
        );
    }

    public function test_desk_persists_box_acknowledgement_metadata_on_success(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/desk/payment-events' => Http::response([
                'status' => 'accepted',
                'first_paid' => true,
                'gateway_order_id' => 'RBP2202',
                'business_order_id' => 'RBP2202',
                'payment_status' => 'Paid',
                'handoff' => [
                    'id' => 88,
                    'status' => 'pending',
                    'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RBP2202',
                ],
            ], 200),
        ]);

        $order = $this->createPaidOrder('RBP2202', 'pay2202', 1999);
        app(RadiumBoxPaymentConfirmationService::class)->confirmForOrder($order);

        $metadata = app(RadiumBoxOrderEnrichmentSyncStore::class)->metadata($order->id);
        $this->assertSame('desk_authoritative_v1', $metadata['downstream_contract'] ?? null);
        $this->assertSame(88, $metadata['handoff_id'] ?? null);
        $this->assertNotEmpty($metadata['box_acknowledged_at'] ?? null);
    }

    public function test_duplicate_ack_from_box_is_treated_as_success(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/desk/payment-events' => Http::response([
                'status' => 'duplicate_ack',
                'first_paid' => false,
                'gateway_order_id' => 'RBP2203',
                'business_order_id' => 'RBP2203',
                'payment_status' => 'Paid',
                'handoff' => [
                    'id' => 89,
                    'status' => 'pending',
                    'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RBP2203',
                ],
            ], 200),
        ]);

        $order = $this->createPaidOrder('RBP2203', 'pay2203', 999);
        $result = app(RadiumBoxPaymentConfirmationService::class)->confirmForOrder($order);

        $this->assertTrue($result->ok);
        $this->assertSame('duplicate_ack', $result->status);
    }

    private function createPaidOrder(string $orderId, string $paymentId, float $amount): Order
    {
        $user = User::factory()->create(['is_active' => true]);

        return Order::query()->create([
            'order_id' => $orderId,
            'cashfree_payment_id' => $paymentId,
            'payment_amount' => $amount,
            'payment_method' => 'cashfree',
            'payment_date' => now(),
            'status' => OrderStatus::Active,
            'created_by' => $user->id,
        ]);
    }
}