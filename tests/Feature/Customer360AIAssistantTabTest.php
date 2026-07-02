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
        $response->assertSee('data-customer-360-section="ai-assistant"', false);
        $response->assertSee('IRA AI', false);
        $response->assertSee('Read only', false);
        $response->assertSee('Customer Summary', false);
        $response->assertSee('Incident Summary', false);
        $response->assertSee('Suggested Next Actions', false);
        $response->assertSee('Suggested Customer Reply', false);
        $response->assertSee('IRA Advisor', false);
        $response->assertSee('Recommendations only', false);
        $response->assertSee('IRA Workspace', false);
        $response->assertSee('Suggested Checklist', false);
        $response->assertSee('Customer Intelligence', false);
        $response->assertSee('Device Intelligence', false);
        $response->assertSee('Operational Intelligence', false);
        $response->assertSee('Business Intelligence', false);
        $response->assertSee('IRA Confidence', false);
        $response->assertSee('IRA Knowledge', false);
        $response->assertSee('Similar Repairs', false);
        $response->assertSee('Common Resolution', false);
        $response->assertSee('Historical Success Rate', false);
        $response->assertSee('Top Recommended Fixes', false);
        $response->assertSee('AI Tab Customer', false);
        $response->assertSee('Request serial number', false);
        $response->assertSee('Powered by <strong>null</strong> provider', false);
    }
}
