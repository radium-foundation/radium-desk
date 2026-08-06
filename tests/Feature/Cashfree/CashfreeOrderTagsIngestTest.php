<?php

namespace Tests\Feature\Cashfree;

use App\Models\AuditLog;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use App\Services\Customer360Service;
use App\Services\RadiumBox\RadiumBoxService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\EnsuresCashfreeSystemUser;
use Tests\TestCase;

class CashfreeOrderTagsIngestTest extends TestCase
{
    use EnsuresCashfreeSystemUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashfree.verify_signature' => false]);

        $this->seed(RolePermissionSeeder::class);
        $this->ensureCashfreeSystemUser();
        $this->seed(SettingsSeeder::class);

        config(['radiumbox.enabled' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function successfulPayloadWithTags(
        string $cfPaymentId = '6178753236',
        string $orderId = 'RD3477557',
        ?array $orderTags = [
            'product_name' => 'MSO 1300 E3 RD L1',
            'rd_service_name' => '1 Year Unlimited',
            'serial_no' => '2521i006956',
        ],
    ): array {
        $payload = [
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'event_time' => '2026-08-06T23:09:12+05:30',
            'data' => [
                'order' => [
                    'order_id' => $orderId,
                    'order_amount' => 499,
                    'order_currency' => 'INR',
                    'order_tags' => $orderTags,
                ],
                'payment' => [
                    'cf_payment_id' => $cfPaymentId,
                    'payment_status' => 'SUCCESS',
                    'payment_amount' => 499,
                    'payment_currency' => 'INR',
                    'payment_time' => '2026-08-06T23:09:00+05:30',
                    'payment_group' => 'upi',
                    'bank_reference' => '234928698581',
                ],
                'customer_details' => [
                    'customer_name' => 'Tag Customer',
                    'customer_email' => 'tags@example.com',
                    'customer_phone' => '9908734801',
                ],
                'payment_gateway_details' => [
                    'gateway_name' => 'CASHFREE',
                    'gateway_order_id' => '1634766330',
                    'gateway_payment_id' => '1504280029',
                ],
            ],
        ];

        if ($orderTags === null) {
            $payload['data']['order']['order_tags'] = null;
        }

        return $payload;
    }

    public function test_success_webhook_with_order_tags_populates_identity_without_radiumbox(): void
    {
        $this->postJson('/api/webhooks/cashfree', $this->successfulPayloadWithTags())
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);

        $order = Order::query()->where('cashfree_payment_id', '6178753236')->first();
        $this->assertNotNull($order);
        $this->assertSame('MSO 1300 E3 RD L1', $order->product_name);
        $this->assertSame('MSO 1300 E3 RD L1', $order->device_model);
        $this->assertSame('2521I006956', $order->serial_number);
        $this->assertSame(['1 Year Unlimited'], $order->service_history);
        $this->assertNotNull($order->serial_entered_at);

        $audit = AuditLog::query()
            ->where('event', CashfreeWebhookProcessorService::AUDIT_EVENT_ORDER_TAGS_IMPORTED)
            ->where('auditable_type', $order->getMorphClass())
            ->where('auditable_id', $order->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame(
            CashfreeWebhookProcessorService::IDENTITY_SOURCE_ORDER_TAGS,
            $audit->new_values['source'] ?? null,
        );
        $this->assertSame('2521I006956', $audit->new_values['serial_number'] ?? null);
    }

    public function test_blank_serial_tag_does_not_populate_serial(): void
    {
        $payload = $this->successfulPayloadWithTags(
            cfPaymentId: '6178402602',
            orderId: 'RD3477547',
            orderTags: [
                'product_name' => 'Access FM220 L1',
                'rd_service_name' => '1 Year Unlimited',
                'serial_no' => '',
            ],
        );

        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();

        $order = Order::query()->where('cashfree_payment_id', '6178402602')->first();
        $this->assertNotNull($order);
        $this->assertSame('Access FM220 L1', $order->product_name);
        $this->assertNull($order->serial_number);
        $this->assertSame(['1 Year Unlimited'], $order->service_history);
    }

    public function test_null_order_tags_keeps_identity_empty(): void
    {
        $this->postJson(
            '/api/webhooks/cashfree',
            $this->successfulPayloadWithTags(
                cfPaymentId: '6178095997',
                orderId: 'RD3477522',
                orderTags: null,
            ),
        )->assertOk();

        $order = Order::query()->where('cashfree_payment_id', '6178095997')->first();
        $this->assertNotNull($order);
        $this->assertNull($order->product_name);
        $this->assertNull($order->serial_number);
        $this->assertNull($order->service_history);
    }

    public function test_duplicate_payment_idempotency_still_holds_with_tags(): void
    {
        $payload = $this->successfulPayloadWithTags();

        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();
        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Incident::query()->count());
        $this->assertSame(2, CashfreeWebhookLog::query()->count());

        $order = Order::query()->first();
        $this->assertSame('2521I006956', $order->serial_number);
        $this->assertSame('MSO 1300 E3 RD L1', $order->product_name);
    }

    public function test_radiumbox_enrichment_does_not_overwrite_order_tag_values(): void
    {
        config([
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
        ]);

        $this->postJson('/api/webhooks/cashfree', $this->successfulPayloadWithTags(
            cfPaymentId: '6179000001',
            orderId: 'RD3479001',
        ))->assertOk();

        $order = Order::query()->where('cashfree_payment_id', '6179000001')->first();
        $this->assertNotNull($order);

        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'order_id' => 'RD3479001',
                        'serial_no' => 'SHOULD-NOT-APPLY',
                        'product_name' => 'Different Model',
                        'rd_service_name' => '3 Years Unlimited',
                    ],
                ],
            ]),
        ]);

        app(RadiumBoxService::class)->enrichOrderFromBackgroundSync($order->fresh());

        $order->refresh();
        $this->assertSame('2521I006956', $order->serial_number);
        $this->assertSame('MSO 1300 E3 RD L1', $order->product_name);
        $this->assertSame('MSO 1300 E3 RD L1', $order->device_model);
        $this->assertSame(['1 Year Unlimited'], $order->service_history);
    }

    public function test_radiumbox_enrichment_fills_only_missing_serial_when_tag_serial_blank(): void
    {
        config([
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
        ]);

        $this->postJson('/api/webhooks/cashfree', $this->successfulPayloadWithTags(
            cfPaymentId: '6179000002',
            orderId: 'RD3479002',
            orderTags: [
                'product_name' => 'MFS110',
                'rd_service_name' => '1 Year Unlimited',
                'serial_no' => '',
            ],
        ))->assertOk();

        $order = Order::query()->where('cashfree_payment_id', '6179000002')->first();
        $this->assertNotNull($order);
        $this->assertNull($order->serial_number);

        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'order_id' => 'RD3479002',
                        'serial_no' => '7891312',
                        'product_name' => 'Should Not Overwrite Product',
                        'rd_service_name' => '3 Years Unlimited',
                    ],
                ],
            ]),
        ]);

        app(RadiumBoxService::class)->enrichOrderFromBackgroundSync($order->fresh());

        $order->refresh();
        $this->assertSame('7891312', $order->serial_number);
        $this->assertSame('MFS110', $order->product_name);
        $this->assertSame(['1 Year Unlimited'], $order->service_history);
    }

    public function test_customer_360_shows_product_serial_and_service_plan_from_tags(): void
    {
        $this->postJson('/api/webhooks/cashfree', $this->successfulPayloadWithTags(
            cfPaymentId: '6179000003',
            orderId: 'RD3479003',
        ))->assertOk();

        $order = Order::query()->where('cashfree_payment_id', '6179000003')->first();
        $this->assertNotNull($order);
        $incident = $order->incidents()->first();
        $this->assertNotNull($incident);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident->update(['assigned_to_user_id' => $agent->id]);

        $payload = app(Customer360Service::class)->drawerData($incident->fresh(['order', 'assignee']));

        $this->assertSame('2521I006956', $payload['device']['serial_number'] ?? null);
        $this->assertSame('MSO 1300 E3 RD L1', $payload['device']['product_name'] ?? null);
        $this->assertSame('1 Year Unlimited', $payload['device']['service_plan'] ?? null);

        $servicePlan = collect($payload['activeServices'] ?? [])
            ->firstWhere('label', 'Service Plan');
        $this->assertNotNull($servicePlan);
        $this->assertSame('1 Year Unlimited', $servicePlan['status']);

        $response = $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk();
        $response->assertSee('MSO 1300 E3 RD L1', false);
        $response->assertSee('2521I006956', false);
        $response->assertSee('1 Year Unlimited', false);
        $response->assertSee('Service Plan', false);
    }
}
