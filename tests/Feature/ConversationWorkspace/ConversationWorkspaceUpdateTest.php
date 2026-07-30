<?php

namespace Tests\Feature\ConversationWorkspace;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationWorkspaceUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['conversation_workspace.enabled' => true]);
    }

    public function test_agent_can_save_mandatory_name_and_need(): void
    {
        [$agent, $incident] = $this->createEnquiryCase();

        $this->actingAs($agent)
            ->patchJson(route('dashboard.service-cases.conversation-workspace.update', $incident), [
                'customer_name' => 'Priya',
                'completed_fields' => ['customer_name'],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('workspace.captured.customer_name', 'Priya');

        $this->actingAs($agent)
            ->patchJson(route('dashboard.service-cases.conversation-workspace.update', $incident), [
                'customer_need' => 'Need a printer for office',
                'completed_fields' => ['customer_need'],
            ])
            ->assertOk()
            ->assertJsonPath('workspace.captured.customer_need', 'Need a printer for office')
            ->assertJsonPath('workspace.active_question.key', 'brand')
            ->assertJsonPath('workspace.mandatory_complete', true);

        $this->assertDatabaseHas('orders', [
            'id' => $incident->order_id,
            'customer_name' => 'Priya',
        ]);
    }

    public function test_disabled_flag_rejects_updates(): void
    {
        config(['conversation_workspace.enabled' => false]);
        [$agent, $incident] = $this->createEnquiryCase();

        $this->actingAs($agent)
            ->patchJson(route('dashboard.service-cases.conversation-workspace.update', $incident), [
                'customer_name' => 'Priya',
            ])
            ->assertStatus(422);
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
            'customer_phone' => '9876501234',
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
            'description' => 'Conversation workspace test',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$agent, $incident];
    }
}
