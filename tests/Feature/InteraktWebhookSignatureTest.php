<?php

namespace Tests\Feature;

use App\Models\InteraktMessage;
use App\Models\InteraktWebhookLog;
use App\Services\Interakt\InteraktWebhookSignatureVerifier;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithInteraktWebhooks;
use Tests\TestCase;

class InteraktWebhookSignatureTest extends TestCase
{
    use InteractsWithInteraktWebhooks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'interakt.webhook_secret' => 'test-interakt-webhook-secret',
            'interakt.verify_signature' => true,
        ]);

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_valid_signature_allows_webhook_processing(): void
    {
        $response = $this->postSignedInteraktWebhook($this->officialIncomingMessagePayload());

        $response->assertOk()->assertExactJson(['status' => 'ok']);

        $log = InteraktWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('processed', $log->processing_status);
        $this->assertSame(1, InteraktMessage::query()->count());
    }

    public function test_invalid_signature_is_rejected_and_still_logged(): void
    {
        $payload = $this->officialIncomingMessagePayload();
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = $this->call(
            'POST',
            '/api/webhooks/interakt',
            [],
            [],
            [],
            [
                'HTTP_Interakt-Signature' => 'sha256=invalid-signature',
                'CONTENT_TYPE' => 'application/json',
            ],
            $rawBody,
        );

        $response->assertUnauthorized();

        $log = InteraktWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('failed', $log->processing_status);
        $this->assertSame(InteraktWebhookSignatureVerifier::ERROR_INVALID_SIGNATURE, $log->processing_error);
        $this->assertSame(0, InteraktMessage::query()->count());
    }

    public function test_missing_signature_header_returns_bad_request(): void
    {
        $response = $this->postJson('/api/webhooks/interakt', $this->officialIncomingMessagePayload());

        $response->assertBadRequest();

        $log = InteraktWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('failed', $log->processing_status);
        $this->assertSame(InteraktWebhookSignatureVerifier::ERROR_INVALID_SIGNATURE, $log->processing_error);
    }

    public function test_signature_verification_uses_webhook_secret_not_api_key(): void
    {
        config([
            'interakt.api_key' => 'different-api-key',
            'interakt.webhook_secret' => 'test-interakt-webhook-secret',
        ]);

        $this->postSignedInteraktWebhook($this->officialIncomingMessagePayload())->assertOk();
    }

    public function test_signature_verification_disabled_allows_unsigned_webhooks(): void
    {
        config(['interakt.verify_signature' => false]);

        $this->postJson('/api/webhooks/interakt', $this->officialIncomingMessagePayload())
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }
}
