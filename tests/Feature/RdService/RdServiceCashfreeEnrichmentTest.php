<?php

namespace Tests\Feature\RdService;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OrderStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Infrastructure\Queue\QueueMetricsService;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\Exceptions\RadiumBoxEnrichmentRetryException;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\EnsuresCashfreeSystemUser;
use Tests\TestCase;

class RdServiceCashfreeEnrichmentTest extends TestCase
{
    use EnsuresCashfreeSystemUser;
    use RefreshDatabase;

    private const TOKEN = 'test-desk-order-token';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->ensureCashfreeSystemUser();
        $this->seed(SettingsSeeder::class);

        config([
            'cashfree.verify_signature' => false,
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
            'radiumbox.admin_fallback_enabled' => true,
            'rdservice.enabled' => true,
            'rdservice.token' => self::TOKEN,
            'rdservice.base_url' => 'https://rdservice.net',
        ]);
    }

    public function test_cashfree_webhook_creates_one_rd_desk_order(): void
    {
        $this->fakeRdServiceSuccess('RD3000003');

        $this->postJson('/api/webhooks/cashfree', $this->cashfreePayload('cf-3000003', 'RD3000003'))
            ->assertOk();

        $this->assertSame(1, Order::query()->count());
        $order = Order::query()->firstOrFail();
        $this->assertSame('RD3000003', $order->order_id);
        $this->assertSame('cf-3000003', $order->cashfree_payment_id);
        $this->assertSame('499.00', $order->payment_amount);
        $this->assertSame(1, Incident::query()->count());
    }

    public function test_rdservice_enrichment_populates_the_same_order(): void
    {
        $this->fakeRdServiceSuccess('RD3000003');

        $this->postJson('/api/webhooks/cashfree', $this->cashfreePayload('cf-3000003', 'RD3000003'))
            ->assertOk();

        $order = Order::query()->where('order_id', 'RD3000003')->firstOrFail();

        $this->assertSame('SN1', $order->serial_number);
        $this->assertSame('MFS110', $order->product_name);
        $this->assertSame('MFS110', $order->device_model);
        $this->assertSame(['1 Year'], $order->service_history);
        $this->assertSame('Payer', $order->customer_name);
        $this->assertSame('07ABCDE1234F1Z5', $order->gst_number);
        $this->assertSame('INV-1', $order->invoice_number);
        $this->assertSame('AMC', $order->amc_status);
        $this->assertSame(RadiumBoxEnrichmentSyncStatus::Synced, $order->radiumbox_sync_status);

        $this->assertRdServiceCalled();
        $this->assertAdminNotCalled();
    }

    public function test_cashfree_first_then_rdservice_enriches_same_record(): void
    {
        $this->fakeRdServiceSuccess('RD3000004');

        $this->postJson('/api/webhooks/cashfree', $this->cashfreePayload('cf-3000004', 'RD3000004'))
            ->assertOk();

        $order = Order::query()->where('order_id', 'RD3000004')->firstOrFail();
        $this->assertSame('cf-3000004', $order->cashfree_payment_id);
        $this->assertSame('SN1', $order->serial_number);
        $this->assertSame(1, Order::query()->where('order_id', 'RD3000004')->count());
        $this->assertAdminNotCalled();
    }

    public function test_rdservice_first_then_cashfree_confirms_payment_on_same_order(): void
    {
        $this->fakeRdServiceSuccess('RD3000003');
        $systemUser = User::query()->where('email', 'superadmin@radium.local')->firstOrFail();

        $order = Order::query()->create([
            'order_id' => 'RD3000003',
            'customer_name' => 'Pending Customer',
            'status' => OrderStatus::Active,
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC39901',
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'Pending RD order',
            'description' => 'Created before Cashfree confirmation.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);

        $this->runEnrichmentJob($order->fresh());

        $order->refresh();
        $this->assertSame('SN1', $order->serial_number);
        $this->assertNull($order->cashfree_payment_id);

        $this->postJson('/api/webhooks/cashfree', $this->cashfreePayload('cf-3000003', 'RD3000003'))
            ->assertOk();

        $this->assertSame(1, Order::query()->count());
        $order->refresh();
        $this->assertSame('cf-3000003', $order->cashfree_payment_id);
        $this->assertSame('499.00', $order->payment_amount);
        $this->assertSame('SN1', $order->serial_number);
    }

    public function test_duplicate_cashfree_webhook_does_not_create_second_order(): void
    {
        $this->fakeRdServiceSuccess('RD3000003');
        $payload = $this->cashfreePayload('cf-3000003', 'RD3000003');

        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();
        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Incident::query()->count());
        $this->assertSame(1, Order::query()->where('cashfree_payment_id', 'cf-3000003')->count());
    }

    public function test_duplicate_rdservice_response_does_not_create_second_order(): void
    {
        $this->fakeRdServiceSuccess('RD3000003');

        $this->postJson('/api/webhooks/cashfree', $this->cashfreePayload('cf-3000003', 'RD3000003'))
            ->assertOk();

        $order = Order::query()->firstOrFail();
        $originalAmount = $order->payment_amount;

        $this->runEnrichmentJob($order);

        $this->assertSame(1, Order::query()->count());
        $order->refresh();
        $this->assertSame($originalAmount, $order->payment_amount);
        $this->assertSame('SN1', $order->serial_number);
    }

    public function test_cashfree_and_rdservice_race_still_one_operational_order(): void
    {
        $this->fakeRdServiceSuccess('RD3000003');
        $payload = $this->cashfreePayload('cf-3000003', 'RD3000003');

        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();
        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();

        $order = Order::query()->firstOrFail();
        $this->runEnrichmentJob($order);
        $this->runEnrichmentJob($order);

        $this->assertSame(1, Order::query()->count());
        $this->assertSame('cf-3000003', $order->fresh()->cashfree_payment_id);
        $this->assertSame('SN1', $order->fresh()->serial_number);
    }

    public function test_rdservice_401_falls_back_to_admin_without_logging_token(): void
    {
        Log::spy();

        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response(['message' => 'Unauthenticated'], 401),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('RD3000003'), 200),
        ]);

        $this->postJson('/api/webhooks/cashfree', $this->cashfreePayload('cf-3000003', 'RD3000003'))
            ->assertOk();

        $order = Order::query()->firstOrFail();
        $this->assertSame('ADMIN-SN', $order->serial_number);
        $this->assertSame('cf-3000003', $order->cashfree_payment_id);
        $this->assertAdminCalled();

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
            $encoded = json_encode([$message, $context], JSON_THROW_ON_ERROR);

            return ! str_contains($encoded, self::TOKEN);
        });
    }

    public function test_rdservice_404_falls_back_to_admin_and_keeps_payment(): void
    {
        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response([
                'status' => 404,
                'message' => 'RD Order not found',
            ], 404),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('RD3000003'), 200),
        ]);

        $this->postJson('/api/webhooks/cashfree', $this->cashfreePayload('cf-3000003', 'RD3000003'))
            ->assertOk();

        $order = Order::query()->firstOrFail();
        $this->assertSame('ADMIN-SN', $order->serial_number);
        $this->assertSame('cf-3000003', $order->cashfree_payment_id);
        $this->assertSame('499.00', $order->payment_amount);
    }

    public function test_rdservice_429_does_not_call_admin_or_duplicate_order(): void
    {
        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response(['message' => 'Too Many Attempts.'], 429),
            'admin.radiumbox.com/*' => Http::response($this->adminPayload('RD3000003'), 200),
        ]);

        $this->postJson('/api/webhooks/cashfree', $this->cashfreePayload('cf-3000003', 'RD3000003'))
            ->assertOk();

        $this->assertSame(1, Order::query()->count());
        $order = Order::query()->firstOrFail();
        $this->assertSame('cf-3000003', $order->cashfree_payment_id);
        $this->assertSame('499.00', $order->payment_amount);
        $this->assertNull($order->serial_number);
        $this->assertAdminNotCalled();
    }

    public function test_rdservice_5xx_keeps_cashfree_payment_without_admin_lookup(): void
    {
        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response('error', 502),
            'admin.radiumbox.com/*' => Http::response($this->adminPayload('RD3000003'), 200),
        ]);

        $this->postJson('/api/webhooks/cashfree', $this->cashfreePayload('cf-3000003', 'RD3000003'))
            ->assertOk();

        $order = Order::query()->firstOrFail();
        $this->assertSame('cf-3000003', $order->cashfree_payment_id);
        $this->assertNull($order->serial_number);
        $this->assertAdminNotCalled();
    }

    public function test_rdservice_429_job_throws_retry_exception(): void
    {
        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response(['message' => 'Too Many Attempts.'], 429),
            'admin.radiumbox.com/*' => Http::response($this->adminPayload('RD3000005'), 200),
        ]);

        $order = $this->createPaidOrderWithoutEnrichment('RD3000005', 'cf-3000005');

        try {
            $this->runEnrichmentJob($order);
            $this->fail('Expected RadiumBoxEnrichmentRetryException.');
        } catch (RadiumBoxEnrichmentRetryException $exception) {
            $this->assertStringContainsString('rate limit', strtolower($exception->getMessage()));
        }

        $order->refresh();
        $this->assertSame('cf-3000005', $order->cashfree_payment_id);
        $this->assertNull($order->serial_number);
        $this->assertAdminNotCalled();
    }

    public function test_rdservice_timeout_does_not_call_admin_or_mutate_payment(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'rdservice.net')) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response($this->adminPayload('RD3000006'), 200);
        });

        $order = $this->createPaidOrderWithoutEnrichment('RD3000006', 'cf-3000006');
        $originalAmount = $order->payment_amount;

        try {
            $this->runEnrichmentJob($order);
            $this->fail('Expected RadiumBoxEnrichmentRetryException.');
        } catch (RadiumBoxEnrichmentRetryException $exception) {
            $this->assertStringContainsString('timed out', strtolower($exception->getMessage()));
        }

        $order->refresh();
        $this->assertSame('cf-3000006', $order->cashfree_payment_id);
        $this->assertSame($originalAmount, $order->payment_amount);
        $this->assertNull($order->serial_number);
        $this->assertAdminNotCalled();
    }

    public function test_malformed_rdservice_response_falls_back_to_admin(): void
    {
        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response(['status' => 200, 'data' => []], 200),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('RD3000003'), 200),
        ]);

        $this->postJson('/api/webhooks/cashfree', $this->cashfreePayload('cf-3000003', 'RD3000003'))
            ->assertOk();

        $order = Order::query()->firstOrFail();
        $this->assertSame('ADMIN-SN', $order->serial_number);
        $this->assertSame('cf-3000003', $order->cashfree_payment_id);
    }

    public function test_legacy_order_continues_using_admin_when_rdservice_returns_404(): void
    {
        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response([
                'status' => 404,
                'message' => 'RD Order not found',
            ], 404),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('RD3478381'), 200),
        ]);

        $this->postJson('/api/webhooks/cashfree', $this->cashfreePayload('cf-legacy-1', 'RD3478381'))
            ->assertOk();

        $order = Order::query()->where('order_id', 'RD3478381')->firstOrFail();
        $this->assertSame('ADMIN-SN', $order->serial_number);
        $this->assertAdminCalled();
    }

    public function test_hardware_rde_order_uses_admin_without_rdservice_http(): void
    {
        Http::fake([
            'https://rdservice.net/*' => Http::response($this->rdServicePayload('RDE1001'), 200),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('RDE1001'), 200),
        ]);

        $order = $this->createPaidOrderWithoutEnrichment('RDE1001', 'cf-rde-1');
        $originalAmount = $order->payment_amount;
        $this->runEnrichmentJob($order);

        $order->refresh();
        $this->assertSame('ADMIN-SN', $order->serial_number);
        $this->assertSame('cf-rde-1', $order->cashfree_payment_id);
        $this->assertSame($originalAmount, $order->payment_amount);
        $this->assertAdminCalled();
        $this->assertRdServiceNotCalled();
    }

    public function test_inq_order_uses_admin_without_rdservice_http(): void
    {
        Http::fake([
            'https://rdservice.net/*' => Http::response($this->rdServicePayload('INQ-SC1001'), 200),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('INQ-SC1001'), 200),
        ]);

        $order = $this->createPaidOrderWithoutEnrichment('INQ-SC1001', 'cf-inq-1');
        $originalAmount = $order->payment_amount;
        $this->runEnrichmentJob($order);

        $order->refresh();
        $this->assertSame('ADMIN-SN', $order->serial_number);
        $this->assertSame('cf-inq-1', $order->cashfree_payment_id);
        $this->assertSame($originalAmount, $order->payment_amount);
        $this->assertAdminCalled();
        $this->assertRdServiceNotCalled();
    }

    public function test_existing_desk_data_is_not_overwritten_by_rdservice_or_empty_admin_fields(): void
    {
        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response(
                $this->rdServicePayload('RD3000007', paymentStatus: 'pending', paymentId: 'cf-from-rdservice', total: '1'),
                200,
            ),
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'order_id' => 'RD3000007',
                        'serial_no' => '',
                        'product_name' => '',
                        'rd_service_name' => '',
                        'gst_no' => '',
                    ],
                ],
            ], 200),
        ]);

        $order = $this->createPaidOrderWithoutEnrichment('RD3000007', 'cf-3000007');
        $order->update([
            'serial_number' => 'DESK-SN',
            'product_name' => 'Desk Product',
            'device_model' => 'Desk Model',
            'gst_number' => '27DESK1234F1Z5',
            'invoice_number' => 'DESK-INV',
            'customer_name' => 'Desk Customer',
            'customer_name_locked_at' => now(),
        ]);

        $originalAmount = $order->fresh()->payment_amount;
        $this->runEnrichmentJob($order->fresh());

        $order->refresh();
        $this->assertSame('DESK-SN', $order->serial_number);
        $this->assertSame('Desk Product', $order->product_name);
        $this->assertSame('Desk Model', $order->device_model);
        $this->assertSame('27DESK1234F1Z5', $order->gst_number);
        $this->assertSame('DESK-INV', $order->invoice_number);
        $this->assertSame('Desk Customer', $order->customer_name);
        $this->assertSame('cf-3000007', $order->cashfree_payment_id);
        $this->assertSame($originalAmount, $order->payment_amount);
        $this->assertNotSame('SN1', $order->serial_number);
        $this->assertNotSame('INV-1', $order->invoice_number);
    }

    public function test_enrichment_disabled_uses_admin_without_rdservice_http(): void
    {
        config(['rdservice.enabled' => false]);

        Http::fake([
            'https://rdservice.net/*' => Http::response($this->rdServicePayload('RD3000008'), 200),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('RD3000008'), 200),
        ]);

        $this->postJson('/api/webhooks/cashfree', $this->cashfreePayload('cf-3000008', 'RD3000008'))
            ->assertOk();

        $order = Order::query()->where('order_id', 'RD3000008')->firstOrFail();
        $this->assertSame('ADMIN-SN', $order->serial_number);
        $this->assertSame('cf-3000008', $order->cashfree_payment_id);
        $this->assertSame('499.00', $order->payment_amount);
        $this->assertAdminCalled();
        $this->assertRdServiceNotCalled();
    }

    public function test_desk_has_no_rdservice_net_prod_database_connection(): void
    {
        $connections = config('database.connections');

        $this->assertIsArray($connections);
        $this->assertArrayNotHasKey('rdservice_net_prod', $connections);
        $this->assertArrayNotHasKey('rdservice_net', $connections);
        $this->assertArrayNotHasKey('radiumbox_prod', $connections);
        $this->assertContains(config('database.default'), ['sqlite', 'mysql', 'mariadb']);
    }

    public function test_enrichment_does_not_overwrite_cashfree_payment_fields(): void
    {
        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response(
                $this->rdServicePayload('RD3000003', paymentStatus: 'pending', paymentId: 'cf-from-rdservice', total: '1'),
                200,
            ),
        ]);

        $this->postJson('/api/webhooks/cashfree', $this->cashfreePayload('cf-3000003', 'RD3000003'))
            ->assertOk();

        $order = Order::query()->firstOrFail();
        $this->assertSame('cf-3000003', $order->cashfree_payment_id);
        $this->assertSame('499.00', $order->payment_amount);
        $this->assertSame('UPI', $order->payment_method);
        $this->assertSame('234928698581', $order->bank_reference);
        $this->assertSame('SN1', $order->serial_number);
        $this->assertNotSame('cf-from-rdservice', $order->cashfree_payment_id);
        $this->assertNotSame('1.00', $order->payment_amount);
    }

    public function test_secrets_are_not_logged_on_successful_enrichment(): void
    {
        Log::spy();
        $this->fakeRdServiceSuccess('RD3000003');

        $this->postJson('/api/webhooks/cashfree', $this->cashfreePayload('cf-3000003', 'RD3000003'))
            ->assertOk();

        Log::shouldHaveReceived('info')->withArgs(function (...$args): bool {
            $encoded = json_encode($args, JSON_THROW_ON_ERROR);

            $this->assertStringNotContainsString(self::TOKEN, $encoded);
            $this->assertStringNotContainsString('Bearer '.self::TOKEN, $encoded);

            return true;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function cashfreePayload(string $cfPaymentId, string $orderId): array
    {
        return [
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'event_time' => '2026-08-30T10:00:00+05:30',
            'data' => [
                'order' => [
                    'order_id' => $orderId,
                    'order_amount' => 499,
                    'order_currency' => 'INR',
                ],
                'payment' => [
                    'cf_payment_id' => $cfPaymentId,
                    'payment_status' => 'SUCCESS',
                    'payment_amount' => 499,
                    'payment_currency' => 'INR',
                    'payment_time' => '2026-08-30T10:00:00+05:30',
                    'payment_group' => 'upi',
                    'bank_reference' => '234928698581',
                ],
                'customer_details' => [
                    'customer_name' => 'Cashfree Customer',
                    'customer_email' => 'cashfree@example.com',
                    'customer_phone' => '9908734801',
                ],
                'payment_gateway_details' => [
                    'gateway_name' => 'CASHFREE',
                    'gateway_order_id' => '1634766330',
                    'gateway_payment_id' => '1504280029',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rdServicePayload(
        string $orderId,
        string $paymentStatus = 'Paid',
        string $paymentId = 'cf-pay-1',
        string $total = '481',
    ): array {
        return [
            'status' => 200,
            'spec_version' => '1.0',
            'website_id' => 'rdservice.net',
            'message' => 'OK',
            'data' => [
                'correlation' => [
                    'rdorderid' => $orderId,
                    'cashfree_order_id' => $orderId,
                    'cashfree_payment_id' => $paymentId,
                    'orders_id' => 10,
                    'ordercode' => 'RD10',
                ],
                'rd_order' => [
                    'id' => 3,
                    'rdorderid' => $orderId,
                    'order_id' => $orderId,
                    'product_name' => 'MFS110',
                    'rd_service_name' => '1 Year',
                    'amc_service_name' => 'AMC',
                    'serial_no' => 'SN1',
                    'gst_no' => '07ABCDE1234F1Z5',
                    'status' => 'Processing',
                    'payment_status' => $paymentStatus,
                    'paid_amount' => $total,
                    'created_at' => '2026-08-30 10:00:00',
                    'userdetails' => json_encode([
                        'name' => 'Payer',
                        'email' => 'payer@example.com',
                        'phone' => '9999999999',
                    ]),
                ],
                'order' => [
                    'invoicecode' => 'INV-1',
                    'payment_status' => $paymentStatus,
                    'payment_id' => $paymentId,
                    'total' => $total,
                    'orderdate' => '2026-08-30 10:00:00',
                ],
                'snapshot' => [
                    'rdorderid' => $orderId,
                    'customer_name' => 'Payer',
                    'email' => 'payer@example.com',
                    'phone' => '9999999999',
                    'gst_number' => '07ABCDE1234F1Z5',
                    'product' => 'MFS110',
                    'model' => 'MFS110',
                    'rd_service' => '1 Year',
                    'amc_service' => 'AMC',
                    'serial_number' => 'SN1',
                    'invoice_number' => 'INV-1',
                    'payment_status' => $paymentStatus,
                    'rd_order_status' => 'Processing',
                    'address' => '1 Test Street',
                ],
                'history' => [['id' => 1, 'status' => 'Being Processing']],
                'lines' => [['id' => 1, 'product_name' => 'RD Service', 'total' => $total]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminPayload(string $orderId): array
    {
        return [
            'status' => 200,
            'data' => [
                'rd_order' => [
                    'order_id' => $orderId,
                    'serial_no' => 'ADMIN-SN',
                    'product_name' => 'Admin Model',
                    'rd_service_name' => 'Admin Service',
                ],
            ],
        ];
    }

    private function fakeRdServiceSuccess(string $orderId): void
    {
        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response($this->rdServicePayload($orderId), 200),
            'admin.radiumbox.com/*' => Http::response($this->adminPayload($orderId), 200),
        ]);
    }

    private function createPaidOrderWithoutEnrichment(string $orderId, string $cfPaymentId): Order
    {
        $systemUser = User::query()->where('email', 'superadmin@radium.local')->firstOrFail();

        return Order::query()->create([
            'order_id' => $orderId,
            'cashfree_payment_id' => $cfPaymentId,
            'payment_amount' => 499,
            'payment_method' => 'UPI',
            'payment_date' => now(),
            'status' => OrderStatus::Active,
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);
    }

    private function runEnrichmentJob(Order $order): void
    {
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markPending($order->id);

        (new RadiumBoxOrderEnrichmentJob($order->id))->handle(
            app(RadiumBoxOrderEnrichmentService::class),
            app(QueueMetricsService::class),
        );
    }

    private function assertRdServiceCalled(): void
    {
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'rdservice.net/api/integrations/v1/rd-orders/'));
    }

    private function assertRdServiceNotCalled(): void
    {
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'rdservice.net'));
    }

    private function assertAdminCalled(): void
    {
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'admin.radiumbox.com/api/search/order'));
    }

    private function assertAdminNotCalled(): void
    {
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'admin.radiumbox.com'));
    }
}
