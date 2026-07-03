<?php

namespace Tests\Unit;

use App\Services\Interakt\InteraktService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class InteraktServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'interakt.api_key' => 'test-interakt-key',
            'interakt.base_url' => 'https://api.interakt.ai',
            'interakt.max_retries' => 2,
            'interakt.retry_delay_ms' => 1,
            'interakt.connect_timeout_seconds' => 5,
            'interakt.timeout_seconds' => 15,
        ]);
    }

    public function test_outbound_request_uses_literal_basic_authorization_header(): void
    {
        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-auth-001'], 200),
        ]);

        app(InteraktService::class)->sendTextMessage('+91', '9876543210', 'Hello');

        Http::assertSent(function ($request): bool {
            $authorization = $request->header('Authorization')[0] ?? null;

            return $request->url() === 'https://api.interakt.ai/v1/public/message/'
                && $authorization === 'Basic test-interakt-key'
                && $authorization !== 'Basic '.base64_encode('test-interakt-key:')
                && ($request->header('Accept')[0] ?? null) === 'application/json'
                && ($request->header('Content-Type')[0] ?? null) === 'application/json';
        });
    }

    public function test_debug_logging_redacts_authorization_header(): void
    {
        Log::shouldReceive('debug')
            ->once()
            ->with('[Interakt] Outbound request', \Mockery::on(function (array $context): bool {
                return ($context['headers']['Authorization'] ?? null) === 'Basic ********'
                    && str_contains((string) ($context['url'] ?? ''), '/v1/public/message/');
            }));

        Log::makePartial();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-debug-001'], 200),
        ]);

        app(InteraktService::class)->sendTextMessage('+91', '9876543210', 'Hello');
    }

    public function test_send_text_message_stores_outgoing_message(): void
    {
        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-text-001'], 200),
        ]);

        $result = app(InteraktService::class)->sendTextMessage(
            countryCode: '+91',
            phoneNumber: '9876543210',
            text: 'Your device is ready for pickup.',
        );

        $this->assertTrue($result->success);
        $this->assertSame('msg-text-001', $result->messageId);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.interakt.ai/v1/public/message/'
                && ($request->header('Authorization')[0] ?? null) === 'Basic test-interakt-key'
                && $request['type'] === 'Text'
                && $request['data']['message'] === 'Your device is ready for pickup.';
        });

        $this->assertDatabaseHas('interakt_messages', [
            'message_id' => 'msg-text-001',
            'text' => 'Your device is ready for pickup.',
            'direction' => 'outgoing',
        ]);
    }

    public function test_server_error_response_is_retriable(): void
    {
        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['message' => 'Server unavailable'], 503),
        ]);

        $result = app(InteraktService::class)->sendTextMessage('+91', '9876543210', 'Hello');

        $this->assertFalse($result->success);
        $this->assertTrue($result->retriable);

        Http::assertSent(function ($request): bool {
            return ($request->header('Authorization')[0] ?? null) === 'Basic test-interakt-key';
        });
    }

    public function test_verify_api_response_requires_message_id(): void
    {
        $service = app(InteraktService::class);

        $this->assertTrue($service->verifyApiResponse(['id' => 'msg-1']));
        $this->assertFalse($service->verifyApiResponse(['status' => 'ok']));
    }
}
