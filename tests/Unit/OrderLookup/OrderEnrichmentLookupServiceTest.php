<?php

namespace Tests\Unit\OrderLookup;

use App\Services\OrderLookup\OrderEnrichmentLookupService;
use App\Services\RdService\RdServiceFetchResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderEnrichmentLookupServiceTest extends TestCase
{
    private const TOKEN = 'test-desk-order-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
            'rdservice.enabled' => false,
            'rdservice.token' => '',
            'rdservice.base_url' => 'https://rdservice.net',
        ]);
    }

    public function test_production_default_uses_admin_without_rdservice_http(): void
    {
        Http::fake([
            'https://rdservice.net/*' => Http::response($this->rdServicePayload('RD3395988'), 200),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('RD3395988'), 200),
        ]);

        $enrichment = app(OrderEnrichmentLookupService::class)->fetchInteractive('RD3395988');

        $this->assertSame('ADMIN-SN', $enrichment?->serialNumber);
        $this->assertRdServiceNotCalled();
        $this->assertAdminCalled();
    }

    public function test_empty_token_keeps_admin_path_even_when_flag_is_on(): void
    {
        config([
            'rdservice.enabled' => true,
            'rdservice.token' => '',
        ]);

        Http::fake([
            'https://rdservice.net/*' => Http::response($this->rdServicePayload('RD3395988'), 200),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('RD3395988'), 200),
        ]);

        $enrichment = app(OrderEnrichmentLookupService::class)->fetchInteractive('RD3395988');

        $this->assertSame('ADMIN-SN', $enrichment?->serialNumber);
        $this->assertRdServiceNotCalled();
        $this->assertAdminCalled();
    }

    public function test_enabled_rdservice_success_skips_admin(): void
    {
        $this->enableRdService();

        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response($this->rdServicePayload('RD3395988'), 200),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('RD3395988'), 200),
        ]);

        $enrichment = app(OrderEnrichmentLookupService::class)->fetchInteractive('RD3395988');

        $this->assertSame('SN1', $enrichment?->serialNumber);
        $this->assertSame('07ABCDE1234F1Z5', $enrichment?->gstNumber);
        $this->assertRdServiceCalled();
        $this->assertAdminNotCalled();
    }

    public function test_rdservice_404_falls_back_to_admin(): void
    {
        $this->enableRdService();

        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response([
                'status' => 404,
                'message' => 'RD Order not found',
            ], 404),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('RD3395988'), 200),
        ]);

        $enrichment = app(OrderEnrichmentLookupService::class)->fetchInteractive('RD3395988');

        $this->assertSame('ADMIN-SN', $enrichment?->serialNumber);
        $this->assertRdServiceCalled();
        $this->assertAdminCalled();
    }

    public function test_interactive_timeout_falls_back_to_admin(): void
    {
        $this->enableRdService();

        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => fn () => throw new ConnectionException('Connection timed out.'),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('RD3395988'), 200),
        ]);

        $enrichment = app(OrderEnrichmentLookupService::class)->fetchInteractive('RD3395988');

        $this->assertSame('ADMIN-SN', $enrichment?->serialNumber);
        $this->assertAdminCalled();
    }

    public function test_background_timeout_does_not_call_admin(): void
    {
        $this->enableRdService();

        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => fn () => throw new ConnectionException('Connection timed out.'),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('RD3395988'), 200),
        ]);

        $result = app(OrderEnrichmentLookupService::class)->fetchForBackgroundSync('RD3395988');

        $this->assertTrue($result->retriable);
        $this->assertSame(RdServiceFetchResult::PROVIDER, $result->provider);
        $this->assertAdminNotCalled();
    }

    public function test_hardware_rde_uses_admin_without_rdservice_http(): void
    {
        $this->enableRdService();

        Http::fake([
            'https://rdservice.net/*' => Http::response($this->rdServicePayload('RDE1001'), 200),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('RDE1001'), 200),
        ]);

        $enrichment = app(OrderEnrichmentLookupService::class)->fetchInteractive('RDE1001');

        $this->assertSame('ADMIN-SN', $enrichment?->serialNumber);
        $this->assertRdServiceNotCalled();
        $this->assertAdminCalled();
    }

    public function test_inq_uses_admin_without_rdservice_http(): void
    {
        $this->enableRdService();

        Http::fake([
            'https://rdservice.net/*' => Http::response($this->rdServicePayload('INQ-SC1001'), 200),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('INQ-SC1001'), 200),
        ]);

        $enrichment = app(OrderEnrichmentLookupService::class)->fetchInteractive('INQ-SC1001');

        $this->assertSame('ADMIN-SN', $enrichment?->serialNumber);
        $this->assertRdServiceNotCalled();
        $this->assertAdminCalled();
    }

    public function test_hardware_rin_uses_admin_without_rdservice_http(): void
    {
        $this->enableRdService();

        Http::fake([
            'https://rdservice.net/*' => Http::response($this->rdServicePayload('RIN1001'), 200),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('RIN1001'), 200),
        ]);

        $enrichment = app(OrderEnrichmentLookupService::class)->fetchInteractive('RIN1001');

        $this->assertSame('ADMIN-SN', $enrichment?->serialNumber);
        $this->assertRdServiceNotCalled();
        $this->assertAdminCalled();
    }

    public function test_rdservice_401_falls_back_to_admin(): void
    {
        $this->enableRdService();

        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response(['message' => 'Unauthenticated'], 401),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('RD3395988'), 200),
        ]);

        $enrichment = app(OrderEnrichmentLookupService::class)->fetchInteractive('RD3395988');

        $this->assertSame('ADMIN-SN', $enrichment?->serialNumber);
        $this->assertRdServiceCalled();
        $this->assertAdminCalled();
    }

    public function test_interactive_429_falls_back_to_admin(): void
    {
        $this->enableRdService();

        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response(['message' => 'Too Many Attempts.'], 429),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('RD3395988'), 200),
        ]);

        $enrichment = app(OrderEnrichmentLookupService::class)->fetchInteractive('RD3395988');

        $this->assertSame('ADMIN-SN', $enrichment?->serialNumber);
        $this->assertAdminCalled();
    }

    public function test_background_429_does_not_call_admin(): void
    {
        $this->enableRdService();

        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '30']),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('RD3395988'), 200),
        ]);

        $result = app(OrderEnrichmentLookupService::class)->fetchForBackgroundSync('RD3395988');

        $this->assertTrue($result->retriable);
        $this->assertTrue($result->isRateLimited());
        $this->assertSame(RdServiceFetchResult::PROVIDER, $result->provider);
        $this->assertAdminNotCalled();
    }

    public function test_interactive_5xx_falls_back_to_admin(): void
    {
        $this->enableRdService();

        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response('error', 502),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('RD3395988'), 200),
        ]);

        $enrichment = app(OrderEnrichmentLookupService::class)->fetchInteractive('RD3395988');

        $this->assertSame('ADMIN-SN', $enrichment?->serialNumber);
        $this->assertAdminCalled();
    }

    public function test_background_5xx_does_not_call_admin(): void
    {
        $this->enableRdService();

        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response('error', 503),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('RD3395988'), 200),
        ]);

        $result = app(OrderEnrichmentLookupService::class)->fetchForBackgroundSync('RD3395988');

        $this->assertTrue($result->retriable);
        $this->assertSame('http_error', $result->errorType);
        $this->assertAdminNotCalled();
    }

    public function test_malformed_rdservice_response_falls_back_to_admin(): void
    {
        $this->enableRdService();

        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response(['status' => 200, 'data' => []], 200),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('RD3395988'), 200),
        ]);

        $enrichment = app(OrderEnrichmentLookupService::class)->fetchInteractive('RD3395988');

        $this->assertSame('ADMIN-SN', $enrichment?->serialNumber);
        $this->assertRdServiceCalled();
        $this->assertAdminCalled();
    }

    public function test_incomplete_rdservice_payload_falls_back_to_admin(): void
    {
        $this->enableRdService();

        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response([
                'status' => 200,
                'data' => [
                    'correlation' => ['rdorderid' => 'RD3395988'],
                    'rd_order' => [
                        'rdorderid' => 'RD3395988',
                        'order_id' => 'RD3395988',
                    ],
                ],
            ], 200),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPayload('RD3395988'), 200),
        ]);

        $enrichment = app(OrderEnrichmentLookupService::class)->fetchInteractive('RD3395988');

        $this->assertSame('ADMIN-SN', $enrichment?->serialNumber);
        $this->assertRdServiceCalled();
        $this->assertAdminCalled();
    }

    public function test_unresolved_lookup_returns_null_without_enrichment(): void
    {
        $this->enableRdService();

        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response([
                'status' => 404,
                'message' => 'RD Order not found',
            ], 404),
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 404,
                'message' => 'RadiumBox order not found.',
            ], 404),
        ]);

        $enrichment = app(OrderEnrichmentLookupService::class)->fetchInteractive('RD3395988');

        $this->assertNull($enrichment);
        $this->assertRdServiceCalled();
        $this->assertAdminCalled();
    }

    private function enableRdService(): void
    {
        config([
            'rdservice.enabled' => true,
            'rdservice.token' => self::TOKEN,
            'rdservice.base_url' => 'https://rdservice.net',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rdServicePayload(string $orderId): array
    {
        return [
            'status' => 200,
            'data' => [
                'correlation' => ['rdorderid' => $orderId],
                'rd_order' => [
                    'rdorderid' => $orderId,
                    'order_id' => $orderId,
                    'product_name' => 'MFS110',
                    'rd_service_name' => '1 Year',
                    'serial_no' => 'SN1',
                    'gst_no' => '07ABCDE1234F1Z5',
                    'status' => 'Processing',
                    'userdetails' => json_encode([
                        'name' => 'Payer',
                        'email' => 'payer@example.com',
                        'phone' => '9999999999',
                    ]),
                ],
                'order' => [
                    'invoicecode' => 'INV-1',
                    'orderdate' => '2026-08-30 10:00:00',
                    'status' => 'Pending',
                ],
                'snapshot' => [
                    'serial_number' => 'SN1',
                    'invoice_number' => 'INV-1',
                    'gst_number' => '07ABCDE1234F1Z5',
                ],
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
