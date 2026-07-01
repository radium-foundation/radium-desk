<?php

namespace Tests\Unit;

use App\Services\Interakt\InteraktService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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
        ]);
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
    }

    public function test_verify_api_response_requires_message_id(): void
    {
        $service = app(InteraktService::class);

        $this->assertTrue($service->verifyApiResponse(['id' => 'msg-1']));
        $this->assertFalse($service->verifyApiResponse(['status' => 'ok']));
    }
}
