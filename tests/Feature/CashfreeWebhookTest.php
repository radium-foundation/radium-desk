<?php

namespace Tests\Feature;

use App\Models\CashfreeWebhookLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashfreeWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_is_publicly_accessible_and_returns_200(): void
    {
        config(['cashfree.verify_signature' => false]);

        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create([
            'email' => 'superadmin@radium.local',
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->seed(SettingsSeeder::class);

        $payload = [
            'type' => 'PAYMENT_FAILED_WEBHOOK',
            'data' => [
                'order' => ['order_id' => 'ORD-123'],
                'payment' => [
                    'cf_payment_id' => '999',
                    'payment_status' => 'FAILED',
                ],
            ],
        ];

        $response = $this->postJson('/api/webhooks/cashfree', $payload, [
            'User-Agent' => 'Cashfree-Webhook/1.0',
            'X-Webhook-Version' => '2023-08-01',
        ]);

        $response->assertOk()
            ->assertExactJson(['status' => 'ok']);

        $this->assertDatabaseCount('cashfree_webhook_logs', 1);

        $log = CashfreeWebhookLog::query()->first();

        $this->assertNotNull($log);
        $this->assertSame('2023-08-01', $log->webhook_version);
        $this->assertSame($payload, $log->request_payload);
        $this->assertNotNull($log->received_at);
        $this->assertSame('127.0.0.1', $log->source_ip);
        $this->assertSame('Cashfree-Webhook/1.0', $log->user_agent);
        $this->assertSame(CashfreeWebhookLog::STATUS_RECEIVED, $log->processing_status);
        $this->assertNull($log->processing_error);
        $this->assertNull($log->processed_at);
        $this->assertStringContainsString('ORD-123', (string) $log->raw_body);
    }

    public function test_webhook_rejects_non_post_requests(): void
    {
        $this->getJson('/api/webhooks/cashfree')->assertMethodNotAllowed();
    }
}
