<?php

namespace Tests\Unit\OrderLookup;

use App\Services\OrderLookup\SpokeOrderClientFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpokeOrderClientHistoricalFetchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.token' => 'test-desk-order-token',
            'order_lookup.spokes.radiumbox_com.historical_invoice_path' => '/api/integrations/v1/historical-invoices/',
        ]);
    }

    public function test_fetch_historical_invoice_by_number_allows_loopback_http_with_host(): void
    {
        config([
            'order_lookup.spokes.radiumbox_com.base_url' => 'http://127.0.0.1',
            'order_lookup.spokes.radiumbox_com.host' => 'radiumbox.com',
        ]);

        Http::fake([
            'http://127.0.0.1/api/integrations/v1/historical-invoices/INV1' => Http::response(['status' => 200], 200),
        ]);

        $client = app(SpokeOrderClientFactory::class)->make('radiumbox_com');
        $payload = $client->fetchHistoricalInvoice('INV1');

        $this->assertIsArray($payload);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), 'http://127.0.0.1/api/integrations/v1/historical-invoices/INV1')
                && $request->hasHeader('Host', 'radiumbox.com');
        });
    }

    public function test_fetch_historical_invoice_by_order_id_rejects_non_https_before_dispatch(): void
    {
        config([
            'order_lookup.spokes.radiumbox_com.base_url' => 'http://127.0.0.1',
            'order_lookup.spokes.radiumbox_com.host' => 'radiumbox.com',
        ]);

        Http::fake();

        $client = app(SpokeOrderClientFactory::class)->make('radiumbox_com');
        $payload = $client->fetchHistoricalInvoiceByOrderId('RDE287954');

        $this->assertIsArray($payload);
        $this->assertSame(0, (int) ($payload['_http_status'] ?? -1));
        $this->assertSame('source_unavailable', $payload['eligibility'] ?? null);
        Http::assertNothingSent();
    }

    public function test_fetch_historical_invoice_by_order_id_uses_https_when_configured(): void
    {
        config([
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.com',
        ]);

        Http::fake([
            'https://radiumbox.com/api/integrations/v1/historical-invoices*' => Http::response(['status' => 200], 200),
        ]);

        $client = app(SpokeOrderClientFactory::class)->make('radiumbox_com');
        $client->fetchHistoricalInvoiceByOrderId('RDE287954');

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), 'https://radiumbox.com/api/integrations/v1/historical-invoices')
                && str_contains($request->url(), 'orderid=RDE287954');
        });
    }
}
