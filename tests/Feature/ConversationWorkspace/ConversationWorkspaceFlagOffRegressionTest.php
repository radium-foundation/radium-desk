<?php

namespace Tests\Feature\ConversationWorkspace;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Customer360Service;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationWorkspaceFlagOffRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'conversation_workspace.enabled' => false,
            'conversation_workspace.auto_create_inquiry_on_answer' => false,
        ]);
    }

    public function test_flags_off_keeps_standard_customer360_chrome_for_enquiry(): void
    {
        [$agent, $incident] = $this->createEnquiryCase();

        $data = app(Customer360Service::class)->drawerData($incident, [
            'live_incoming_call' => true,
            'call_id' => 'call-flag-off',
        ]);

        $this->assertNull($data['conversationWorkspace']);

        $html = $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', [
                'incident' => $incident,
                'cw' => 1,
                'call_id' => 'call-flag-off',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-conversation-workspace', $html);
        $this->assertStringContainsString('data-customer-360-section="operations-header"', $html);
        $this->assertStringContainsString('data-customer-360-tab="overview"', $html);
        $this->assertStringContainsString('data-customer-360-tab="timeline"', $html);
        $this->assertStringContainsString('data-customer-360-tab="ai-assistant"', $html);
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createEnquiryCase(): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $reference = app(IncidentReferenceService::class)->generate();
        $order = Order::query()->create([
            'order_id' => Order::inquiryOrderIdFromReference($reference),
            'customer_phone' => '9876509999',
            'status' => 'active',
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $reference,
            'category' => 'General Support',
            'source' => IncidentSource::Call,
            'title' => 'Enquiry call',
            'description' => 'Flag-off regression',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$agent, $incident];
    }
}
