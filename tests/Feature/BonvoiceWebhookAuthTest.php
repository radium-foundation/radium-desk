<?php

namespace Tests\Feature;

use App\Models\BonvoiceCallEvent;
use App\Models\BonvoiceWebhookLog;
use App\Services\Bonvoice\BonvoiceWebhookAuthVerifier;
use App\Services\Bonvoice\BonvoiceWebhookSignatureVerifier;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BonvoiceWebhookAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bonvoice.webhook_token' => 'test-bonvoice-token',
            'bonvoice.account_id' => 'acct-001',
            'bonvoice.verify_webhook_auth' => true,
            'bonvoice.require_bearer' => false,
            'bonvoice.verify_signature' => false,
        ]);

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_accepts_valid_account_id_without_bearer(): void
    {
        $response = $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload());

        $response->assertOk()->assertExactJson(['status' => 'ok']);

        $log = BonvoiceWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('processed', $log->processing_status);
        $this->assertSame(1, BonvoiceCallEvent::query()->count());
    }

    public function test_rejects_missing_account_id_when_webhook_auth_enabled(): void
    {
        $payload = $this->inboundCallPayload();
        unset($payload['AccountID']);

        $response = $this->postJson('/api/webhooks/bonvoice', $payload);

        $response->assertBadRequest()
            ->assertJson([
                'status' => 'error',
                'message' => BonvoiceWebhookAuthVerifier::ERROR_MISSING_ACCOUNT_ID,
            ]);

        $log = BonvoiceWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('failed', $log->processing_status);
        $this->assertSame(BonvoiceWebhookAuthVerifier::ERROR_MISSING_ACCOUNT_ID, $log->processing_error);
        $this->assertSame(0, BonvoiceCallEvent::query()->count());
    }

    public function test_rejects_wrong_account_id_when_webhook_auth_enabled(): void
    {
        $payload = $this->inboundCallPayload(accountId: 'acct-wrong');

        $response = $this->postJson('/api/webhooks/bonvoice', $payload);

        $response->assertUnauthorized()
            ->assertJson([
                'status' => 'error',
                'message' => BonvoiceWebhookAuthVerifier::ERROR_INVALID_ACCOUNT_ID,
            ]);

        $log = BonvoiceWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('failed', $log->processing_status);
        $this->assertSame(BonvoiceWebhookAuthVerifier::ERROR_INVALID_ACCOUNT_ID, $log->processing_error);
        $this->assertSame(0, BonvoiceCallEvent::query()->count());
    }

    public function test_bearer_is_optional_when_only_webhook_auth_is_enabled(): void
    {
        $response = $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload());

        $response->assertOk();
        $this->assertSame(1, BonvoiceCallEvent::query()->count());
    }

    public function test_rejects_missing_bearer_when_require_bearer_is_enabled(): void
    {
        config([
            'bonvoice.require_bearer' => true,
        ]);

        $response = $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload());

        $response->assertBadRequest()
            ->assertJson([
                'status' => 'error',
                'message' => BonvoiceWebhookAuthVerifier::ERROR_MISSING_AUTHORIZATION,
            ]);

        $log = BonvoiceWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('failed', $log->processing_status);
        $this->assertSame(BonvoiceWebhookAuthVerifier::ERROR_MISSING_AUTHORIZATION, $log->processing_error);
        $this->assertSame(0, BonvoiceCallEvent::query()->count());
    }

    public function test_accepts_valid_bearer_when_require_bearer_is_enabled(): void
    {
        config([
            'bonvoice.require_bearer' => true,
        ]);

        $response = $this->postJson(
            '/api/webhooks/bonvoice',
            $this->inboundCallPayload(),
            ['Authorization' => 'Bearer test-bonvoice-token'],
        );

        $response->assertOk()->assertExactJson(['status' => 'ok']);
        $this->assertSame(1, BonvoiceCallEvent::query()->count());
    }

    public function test_rejects_invalid_bearer_when_require_bearer_is_enabled(): void
    {
        config([
            'bonvoice.require_bearer' => true,
        ]);

        $response = $this->postJson(
            '/api/webhooks/bonvoice',
            $this->inboundCallPayload(),
            ['Authorization' => 'Bearer wrong-token'],
        );

        $response->assertUnauthorized()
            ->assertJson([
                'status' => 'error',
                'message' => BonvoiceWebhookAuthVerifier::ERROR_INVALID_AUTHORIZATION,
            ]);

        $log = BonvoiceWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('failed', $log->processing_status);
        $this->assertSame(BonvoiceWebhookAuthVerifier::ERROR_INVALID_AUTHORIZATION, $log->processing_error);
        $this->assertSame(0, BonvoiceCallEvent::query()->count());
    }

    public function test_deprecated_verify_signature_still_requires_bearer(): void
    {
        config([
            'bonvoice.verify_webhook_auth' => false,
            'bonvoice.verify_signature' => true,
        ]);

        $response = $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload());

        $response->assertBadRequest()
            ->assertJson([
                'status' => 'error',
                'message' => BonvoiceWebhookAuthVerifier::ERROR_MISSING_AUTHORIZATION,
            ]);

        $log = BonvoiceWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('failed', $log->processing_status);
        $this->assertSame(BonvoiceWebhookAuthVerifier::ERROR_MISSING_AUTHORIZATION, $log->processing_error);
    }

    public function test_deprecated_verify_signature_accepts_valid_bearer(): void
    {
        config([
            'bonvoice.verify_webhook_auth' => false,
            'bonvoice.verify_signature' => true,
        ]);

        $response = $this->postJson(
            '/api/webhooks/bonvoice',
            $this->inboundCallPayload(),
            ['Authorization' => 'Bearer test-bonvoice-token'],
        );

        $response->assertOk()->assertExactJson(['status' => 'ok']);

        $log = BonvoiceWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('processed', $log->processing_status);
        $this->assertSame(1, BonvoiceCallEvent::query()->count());
    }

    public function test_deprecated_verify_signature_rejects_invalid_token(): void
    {
        config([
            'bonvoice.verify_webhook_auth' => false,
            'bonvoice.verify_signature' => true,
        ]);

        $response = $this->postJson(
            '/api/webhooks/bonvoice',
            $this->inboundCallPayload(),
            ['Authorization' => 'Bearer wrong-token'],
        );

        $response->assertUnauthorized()
            ->assertJson([
                'status' => 'error',
                'message' => BonvoiceWebhookSignatureVerifier::ERROR_INVALID_AUTHORIZATION,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundCallPayload(string $accountId = 'acct-001'): array
    {
        return [
            'SourceNumber' => '9876543210',
            'DestinationNumber' => '1800123456',
            'DisplayNumber' => '1800123456',
            'StartTime' => Carbon::parse('2026-07-08T10:15:00')->toIso8601String(),
            'DataSource' => 'IVR',
            'callType' => 'Support',
            'AccountID' => $accountId,
            'callID' => 'call-auth-001',
            'Direction' => 'Inbound',
            'Leg' => 'A',
            'Status' => 'Ringing',
            'AgentStatus' => 'Idle',
            'eventID' => 'evt-auth-001',
            'callBackParentID' => null,
            'callBackParams' => null,
        ];
    }
}
