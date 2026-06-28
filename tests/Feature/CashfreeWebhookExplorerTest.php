<?php

namespace Tests\Feature;

use App\Models\CashfreeWebhookLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashfreeWebhookExplorerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function createWebhookLog(array $overrides = []): CashfreeWebhookLog
    {
        return CashfreeWebhookLog::query()->create(array_merge([
            'webhook_version' => '2023-08-01',
            'request_headers' => ['content-type' => ['application/json']],
            'request_payload' => ['type' => 'PAYMENT_SUCCESS'],
            'raw_body' => '{"type":"PAYMENT_SUCCESS"}',
            'received_at' => now(),
            'source_ip' => '103.12.34.56',
            'user_agent' => 'Cashfree-Webhook/1.0',
            'processing_status' => CashfreeWebhookLog::STATUS_RECEIVED,
            'processing_error' => null,
            'processed_at' => null,
        ], $overrides));
    }

    public function test_agent_cannot_access_webhook_explorer(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $log = $this->createWebhookLog();

        $this->actingAs($agent)->get(route('cashfree.webhook-explorer.index'))->assertForbidden();
        $this->actingAs($agent)->get(route('cashfree.webhook-explorer.show', $log))->assertForbidden();
    }

    public function test_admin_can_view_webhook_explorer_list_and_detail(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $log = $this->createWebhookLog([
            'request_payload' => ['type' => 'PAYMENT_FAILED', 'order_id' => 'ORD-999'],
        ]);

        $this->actingAs($admin)
            ->get(route('cashfree.webhook-explorer.index'))
            ->assertOk()
            ->assertSee('Webhook Explorer')
            ->assertSee('Security')
            ->assertSee('Signature Verification')
            ->assertSee('Disabled')
            ->assertSee('#'.$log->id)
            ->assertSee('PAYMENT_FAILED')
            ->assertSee('103.12.34.56')
            ->assertSee('Cashfree-Webhook/1.0')
            ->assertSee('Received');

        $this->actingAs($admin)
            ->get(route('cashfree.webhook-explorer.show', $log))
            ->assertOk()
            ->assertSee('Processing Status')
            ->assertSee('Source IP')
            ->assertSee('User Agent')
            ->assertSee('Processing Error')
            ->assertSee('Processed At')
            ->assertSee('Parsed Payload')
            ->assertSee('Request Headers')
            ->assertSee('Raw Body')
            ->assertSee('ORD-999');
    }

    public function test_admin_can_search_webhook_logs_by_id(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $first = $this->createWebhookLog();
        $second = $this->createWebhookLog();

        $this->actingAs($admin)
            ->get(route('cashfree.webhook-explorer.index', ['q' => (string) $second->id]))
            ->assertOk()
            ->assertSee('#'.$second->id)
            ->assertDontSee('#'.$first->id);
    }

    public function test_webhook_explorer_shows_enabled_signature_verification_status(): void
    {
        config(['cashfree.verify_signature' => true]);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createWebhookLog();

        $this->actingAs($admin)
            ->get(route('cashfree.webhook-explorer.index'))
            ->assertOk()
            ->assertSee('Signature Verification')
            ->assertSee('Enabled');
    }
}
