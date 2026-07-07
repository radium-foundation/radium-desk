<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Customer360AIAssistantTabTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_customer_360_renders_ai_assistant_tab(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-AI-TAB',
            'serial_number' => '',
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => 'TXN-AI-TAB',
            'customer_name' => 'AI Tab Customer',
            'customer_email' => 'aitab@example.com',
            'customer_phone' => '9111222333',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'AI tab case',
            'description' => 'AI tab case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $response = $this->actingAs($agent)->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk();
        $response->assertSee('data-customer-360-tab="ai-assistant"', false);
        $response->assertSee('data-customer-360-tab="timeline"', false);
        $response->assertSee('data-customer-360-ai-tab', false);
        $response->assertSee('IRA AI', false);

        $aiTabHtml = (string) $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.customer-360.ai-workbench', $incident))
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('data-customer-360-section="ai-assistant"', $aiTabHtml);
        $this->assertStringContainsString('Read only', $aiTabHtml);
        $this->assertStringContainsString('Customer Summary', $aiTabHtml);
        $this->assertStringContainsString('Incident Summary', $aiTabHtml);
        $this->assertStringContainsString('Suggested Next Actions', $aiTabHtml);
        $this->assertStringContainsString('Suggested Customer Reply', $aiTabHtml);
        $this->assertStringContainsString('IRA Advisor', $aiTabHtml);
        $this->assertStringContainsString('Recommendations only', $aiTabHtml);
        $this->assertStringContainsString('IRA Workspace', $aiTabHtml);
        $this->assertStringContainsString('Suggested Checklist', $aiTabHtml);
        $this->assertStringContainsString('Customer Intelligence', $aiTabHtml);
        $this->assertStringContainsString('Device Intelligence', $aiTabHtml);
        $this->assertStringContainsString('Operational Intelligence', $aiTabHtml);
        $this->assertStringContainsString('Business Intelligence', $aiTabHtml);
        $this->assertStringContainsString('IRA Confidence', $aiTabHtml);
        $this->assertStringContainsString('IRA Knowledge', $aiTabHtml);
        $this->assertStringContainsString('Similar Repairs', $aiTabHtml);
        $this->assertStringContainsString('Common Resolution', $aiTabHtml);
        $this->assertStringContainsString('Historical Success Rate', $aiTabHtml);
        $this->assertStringContainsString('Top Recommended Fixes', $aiTabHtml);
        $this->assertStringContainsString('AI Tab Customer', $aiTabHtml);
        $this->assertStringContainsString('Request serial number', $aiTabHtml);
        $this->assertStringContainsString('Powered by <strong>null</strong> provider', $aiTabHtml);
    }
}
