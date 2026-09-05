<?php

namespace Tests\Unit\RdService;

use App\Services\RdService\RdServiceClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RdServiceClientTest extends TestCase
{
    private const TOKEN = 'test-desk-secret-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rdservice.enabled' => true,
            'rdservice.token' => self::TOKEN,
            'rdservice.base_url' => 'https://rdservice.net',
            'rdservice.connect_timeout_seconds' => 3,
            'rdservice.timeout_seconds' => 8,
        ]);
    }

    public function test_it_sends_bearer_token_over_https_path(): void
    {
        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/RD3000003' => Http::response($this->okPayload(), 200),
        ]);

        $result = app(RdServiceClient::class)->fetch('RD3000003');

        $this->assertTrue($result->succeeded());
        $this->assertSame('SN1', $result->enrichment?->serialNumber);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://rdservice.net/api/integrations/v1/rd-orders/RD3000003'
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && $request->method() === 'GET';
        });
    }

    public function test_it_skips_invalid_order_id_without_http(): void
    {
        Http::fake();

        $result = app(RdServiceClient::class)->fetch('rd3000003');

        $this->assertFalse($result->succeeded());
        $this->assertTrue($result->fallbackToAdmin);
        $this->assertSame('invalid_order_id', $result->errorType);
        Http::assertNothingSent();
    }

    public function test_it_skips_hardware_order_ids_without_http(): void
    {
        Http::fake();

        $this->assertFalse(app(RdServiceClient::class)->isEligible('RDE1001'));

        $result = app(RdServiceClient::class)->fetch('RDE1001');

        $this->assertSame('invalid_order_id', $result->errorType);
        $this->assertTrue($result->fallbackToAdmin);
        $this->assertFalse($result->retriable);
        Http::assertNothingSent();
    }

    public function test_it_skips_inquiry_order_ids_without_http(): void
    {
        Http::fake();

        $this->assertFalse(app(RdServiceClient::class)->isEligible('INQ-SC1001'));

        $result = app(RdServiceClient::class)->fetch('INQ-SC1001');

        $this->assertSame('invalid_order_id', $result->errorType);
        $this->assertTrue($result->fallbackToAdmin);
        $this->assertFalse($result->retriable);
        Http::assertNothingSent();
    }

    public function test_production_config_default_is_disabled(): void
    {
        $source = file_get_contents(base_path('config/rdservice.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("env('RDSERVICE_ENABLED', false)", $source);
        $this->assertStringNotContainsString("env('RDSERVICE_ENABLED', true)", $source);
    }

    public function test_disabled_flag_skips_http_and_falls_back_to_admin(): void
    {
        config(['rdservice.enabled' => false]);
        Http::fake();

        $this->assertFalse(app(RdServiceClient::class)->isConfigured());
        $this->assertFalse(app(RdServiceClient::class)->isEligible('RD3000003'));

        $result = app(RdServiceClient::class)->fetch('RD3000003');

        $this->assertSame('disabled', $result->errorType);
        $this->assertTrue($result->fallbackToAdmin);
        $this->assertFalse($result->retriable);
        Http::assertNothingSent();
    }

    public function test_it_rejects_non_https_base_url(): void
    {
        config(['rdservice.base_url' => 'http://rdservice.net']);
        Http::fake();
        Log::spy();

        $this->assertFalse(app(RdServiceClient::class)->isConfigured());

        $result = app(RdServiceClient::class)->fetch('RD3000003');

        $this->assertTrue($result->fallbackToAdmin);
        $this->assertSame('insecure_base_url', $result->errorType);
        Http::assertNothingSent();
        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'HTTPS'));
    }

    public function test_loopback_http_without_host_is_rejected(): void
    {
        config([
            'rdservice.base_url' => 'http://127.0.0.1',
            'rdservice.host' => '',
        ]);
        Http::fake();

        $this->assertFalse(app(RdServiceClient::class)->isConfigured());
        $result = app(RdServiceClient::class)->fetch('RD3000003');

        $this->assertSame('insecure_base_url', $result->errorType);
        Http::assertNothingSent();
    }

    public function test_loopback_http_sends_configured_host_header(): void
    {
        config([
            'rdservice.base_url' => 'http://127.0.0.1',
            'rdservice.host' => 'rdservice.net',
        ]);
        Http::fake([
            'http://127.0.0.1/api/integrations/v1/rd-orders/RD3000003' => Http::response($this->okPayload(), 200),
        ]);

        $this->assertTrue(app(RdServiceClient::class)->isConfigured());
        $result = app(RdServiceClient::class)->fetch('RD3000003');

        $this->assertTrue($result->succeeded());
        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1/api/integrations/v1/rd-orders/RD3000003'
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && $request->hasHeader('Host', 'rdservice.net');
        });
    }

    public function test_401_does_not_log_token(): void
    {
        Http::fake([
            'rdservice.net/*' => Http::response(['message' => 'Unauthenticated'], 401),
        ]);
        Log::spy();

        $result = app(RdServiceClient::class)->fetch('RD3000003');

        $this->assertSame('unauthorized', $result->errorType);
        $this->assertSame(401, $result->httpStatus);
        $this->assertTrue($result->fallbackToAdmin);
        $this->assertFalse($result->retriable);

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
            $encoded = json_encode([$message, $context], JSON_THROW_ON_ERROR);

            return $message === 'RDService authentication failed.'
                && ! str_contains($encoded, self::TOKEN)
                && ! str_contains($encoded, 'Bearer');
        });
    }

    public function test_404_is_non_retriable_admin_fallback(): void
    {
        Http::fake([
            'rdservice.net/*' => Http::response(['status' => 404, 'message' => 'RD Order not found'], 404),
        ]);

        $result = app(RdServiceClient::class)->fetch('RD3000003');

        $this->assertSame('order_not_found', $result->errorType);
        $this->assertTrue($result->fallbackToAdmin);
        $this->assertFalse($result->retriable);
    }

    public function test_429_is_retriable(): void
    {
        Http::fake([
            'rdservice.net/*' => Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '30']),
        ]);

        $result = app(RdServiceClient::class)->fetch('RD3000003');

        $this->assertTrue($result->retriable);
        $this->assertFalse($result->fallbackToAdmin);
        $this->assertSame('rate_limited', $result->errorType);
        $this->assertSame(30, $result->retryAfterSeconds);
    }

    public function test_5xx_is_retriable(): void
    {
        Http::fake([
            'rdservice.net/*' => Http::response('error', 503),
        ]);

        $result = app(RdServiceClient::class)->fetch('RD3000003');

        $this->assertTrue($result->retriable);
        $this->assertFalse($result->fallbackToAdmin);
        $this->assertSame('http_error', $result->errorType);
        $this->assertSame(503, $result->httpStatus);
    }

    public function test_timeout_is_retriable(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $result = app(RdServiceClient::class)->fetch('RD3000003');

        $this->assertTrue($result->retriable);
        $this->assertFalse($result->fallbackToAdmin);
        $this->assertSame('connection_error', $result->errorType);
        $this->assertStringNotContainsString(self::TOKEN, (string) $result->errorMessage);
    }

    public function test_malformed_payload_falls_back_to_admin(): void
    {
        Http::fake([
            'rdservice.net/*' => Http::response(['status' => 200, 'data' => 'nope'], 200),
        ]);

        $result = app(RdServiceClient::class)->fetch('RD3000003');

        $this->assertFalse($result->retriable);
        $this->assertTrue($result->fallbackToAdmin);
        $this->assertSame('invalid_response', $result->errorType);
    }

    public function test_empty_token_does_not_send_http(): void
    {
        config(['rdservice.token' => '']);
        Http::fake();

        $this->assertFalse(app(RdServiceClient::class)->isConfigured());
        $result = app(RdServiceClient::class)->fetch('RD3000003');

        $this->assertSame('not_configured', $result->errorType);
        $this->assertTrue($result->fallbackToAdmin);
        Http::assertNothingSent();
    }

    /**
     * @return array<string, mixed>
     */
    private function okPayload(): array
    {
        return [
            'status' => 200,
            'data' => [
                'correlation' => [
                    'rdorderid' => 'RD3000003',
                    'cashfree_order_id' => 'RD3000003',
                ],
                'rd_order' => [
                    'rdorderid' => 'RD3000003',
                    'order_id' => 'RD3000003',
                    'serial_no' => 'SN1',
                    'product_name' => 'MFS110',
                    'rd_service_name' => '1 Year',
                    'userdetails' => json_encode(['name' => 'Payer']),
                ],
                'order' => null,
                'snapshot' => [
                    'serial_number' => 'SN1',
                    'model' => 'MFS110',
                ],
                'history' => [],
                'lines' => [],
            ],
        ];
    }
}
