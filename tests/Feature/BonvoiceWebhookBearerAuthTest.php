<?php

namespace Tests\Feature;

use App\Models\BonvoiceCallEvent;
use App\Models\BonvoiceWebhookLog;
use App\Services\Bonvoice\BonvoiceWebhookSignatureVerifier;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BonvoiceWebhookBearerAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bonvoice.webhook_token' => 'test-bonvoice-token',
            'bonvoice.verify_signature' => true,
            'bonvoice.account_id' => 'acct-001',
        ]);

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_accepts_valid_bearer_token(): void
    {
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

    public function test_rejects_missing_authorization_header_when_verification_enabled(): void
    {
        $response = $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload());

        $response->assertBadRequest();

        $log = BonvoiceWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('failed', $log->processing_status);
        $this->assertSame(BonvoiceWebhookSignatureVerifier::ERROR_INVALID_AUTHORIZATION, $log->processing_error);
        $this->assertSame(0, BonvoiceCallEvent::query()->count());
    }

    public function test_rejects_invalid_token(): void
    {
        $response = $this->postJson(
            '/api/webhooks/bonvoice',
            $this->inboundCallPayload(),
            ['Authorization' => 'Bearer wrong-token'],
        );

        $response->assertUnauthorized();

        $log = BonvoiceWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('failed', $log->processing_status);
        $this->assertSame(BonvoiceWebhookSignatureVerifier::ERROR_INVALID_AUTHORIZATION, $log->processing_error);
        $this->assertSame(0, BonvoiceCallEvent::query()->count());
    }

    public function test_rejects_authorization_header_without_bearer_prefix(): void
    {
        $response = $this->postJson(
            '/api/webhooks/bonvoice',
            $this->inboundCallPayload(),
            ['Authorization' => 'test-bonvoice-token'],
        );

        $response->assertUnauthorized();

        $log = BonvoiceWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('failed', $log->processing_status);
        $this->assertSame(0, BonvoiceCallEvent::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundCallPayload(): array
    {
        return [
            'SourceNumber' => '9876543210',
            'DestinationNumber' => '1800123456',
            'DisplayNumber' => '1800123456',
            'StartTime' => Carbon::parse('2026-07-08T10:15:00')->toIso8601String(),
            'DataSource' => 'IVR',
            'callType' => 'Support',
            'AccountID' => 'acct-001',
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
