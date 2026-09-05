<?php

namespace Tests\Unit\OrderLookup;

use App\Services\OrderLookup\OrderEnrichmentLookupService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderSourceRoutingTest extends TestCase
{
    private const TOKEN = 'test-desk-order-token';

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRetiredAdminOrderFallback();
        config([
            'rdservice.enabled' => true,
            'rdservice.token' => self::TOKEN,
            'rdservice.base_url' => 'https://rdservice.net',
            'order_lookup.spokes.rdservice_in.enabled' => true,
            'order_lookup.spokes.rdservice_in.token' => self::TOKEN,
            'order_lookup.spokes.rdservice_in.base_url' => 'https://rdservice.in',
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.token' => self::TOKEN,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.com',
        ]);
    }

    public function test_rd3449705_uses_net_and_never_calls_admin(): void
    {
        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/RD3449705' => Http::response($this->payload('RD3449705', 'INV6745886'), 200),
            'https://rdservice.in/*' => Http::response(['status' => 404, 'message' => 'RD Order not found'], 404),
            'https://radiumbox.com/*' => Http::response(['status' => 404, 'message' => 'RD Order not found'], 404),
            'https://admin.radiumbox.com/*' => Http::response(['status' => 200], 200),
        ]);

        $enrichment = app(OrderEnrichmentLookupService::class)->fetchInteractive('RD3449705');

        $this->assertSame('INV6745886', $enrichment?->invoiceNumber);
        $this->assertSame('7710951', $enrichment?->serialNumber);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'admin.radiumbox.com'));
    }

    public function test_net_404_fans_out_to_rdservice_in(): void
    {
        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/RD3511916' => Http::response(['status' => 404, 'message' => 'RD Order not found'], 404),
            'https://rdservice.in/api/integrations/v1/rd-orders/RD3511916' => Http::response($this->payload('RD3511916', null), 200),
            'https://admin.radiumbox.com/*' => Http::response(['status' => 200], 200),
        ]);

        $enrichment = app(OrderEnrichmentLookupService::class)->fetchInteractive('RD3511916');

        $this->assertSame('7710951', $enrichment?->serialNumber);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'admin.radiumbox.com'));
    }

    public function test_rde_uses_box_and_skips_net(): void
    {
        Http::fake([
            'https://radiumbox.com/api/integrations/v1/rd-orders/RDE318360' => Http::response($this->payload('RDE318360', 'INV100'), 200),
            'https://rdservice.net/*' => Http::response(['status' => 400], 400),
            'https://admin.radiumbox.com/*' => Http::response(['status' => 200], 200),
        ]);

        $enrichment = app(OrderEnrichmentLookupService::class)->fetchInteractive('RDE318360');

        $this->assertSame('INV100', $enrichment?->invoiceNumber);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'rdservice.net'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'admin.radiumbox.com'));
    }

    public function test_unknown_order_does_not_call_admin(): void
    {
        Http::fake([
            'https://rdservice.net/*' => Http::response(['status' => 404, 'message' => 'RD Order not found'], 404),
            'https://rdservice.in/*' => Http::response(['status' => 404, 'message' => 'RD Order not found'], 404),
            'https://radiumbox.com/*' => Http::response(['status' => 404, 'message' => 'RD Order not found'], 404),
            'https://admin.radiumbox.com/*' => Http::response($this->payload('RD9999999', 'INVX'), 200),
        ]);

        $enrichment = app(OrderEnrichmentLookupService::class)->fetchInteractive('RD9999999');

        $this->assertNull($enrichment);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'admin.radiumbox.com'));
    }

    public function test_malformed_identifier_sends_no_http(): void
    {
        Http::fake();

        $this->assertNull(app(OrderEnrichmentLookupService::class)->fetchInteractive('rd'));
        Http::assertNothingSent();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $orderId, ?string $invoice): array
    {
        return [
            'status' => 200,
            'spec_version' => '1.0',
            'website_id' => 'rdservice.net',
            'message' => 'OK',
            'data' => [
                'correlation' => ['rdorderid' => $orderId],
                'rd_order' => [
                    'rdorderid' => $orderId,
                    'order_id' => $orderId,
                    'serial_no' => '7710951',
                    'product_name' => 'MFS110',
                    'status' => 'Completed',
                    'payment_status' => 'Paid',
                    'userdetails' => json_encode([
                        'name' => 'Nareshkumar',
                        'phone' => '9999999999',
                    ]),
                ],
                'order' => [
                    'id' => 268507,
                    'invoicecode' => $invoice,
                    'payment_status' => 'Paid',
                    'status' => 'Completed',
                ],
                'snapshot' => [
                    'rdorderid' => $orderId,
                    'serial_number' => '7710951',
                    'invoice_number' => $invoice,
                    'payment_status' => 'Paid',
                    'model' => 'MFS110',
                ],
            ],
        ];
    }
}
