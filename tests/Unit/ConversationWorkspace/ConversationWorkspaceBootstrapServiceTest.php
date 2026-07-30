<?php

namespace Tests\Unit\ConversationWorkspace;

use App\Enums\BonvoiceCallAlertType;
use App\Models\BonvoiceCallAlert;
use App\Models\BonvoiceCallEvent;
use App\Models\ConversationWorkspaceSession;
use App\Models\Order;
use App\Models\User;
use App\Services\ConversationWorkspace\ConversationWorkspaceBootstrapService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConversationWorkspaceBootstrapServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'conversation_workspace.enabled' => true,
            'conversation_workspace.auto_create_inquiry_on_answer' => true,
        ]);
    }

    public function test_creates_inquiry_and_session_for_unknown_caller(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $event = BonvoiceCallEvent::query()->create([
            'call_id' => 'call-boot-001',
            'event_id' => 'evt-boot-001',
            'direction' => 'Inbound',
            'status' => 'ANSWERED',
            'leg' => 'A',
            'customer_phone' => '9111555666',
            'agent_extension' => '1800123456',
            'started_at' => Carbon::now(),
            'payload' => [],
        ]);

        $alert = BonvoiceCallAlert::query()->create([
            'bonvoice_call_event_id' => $event->id,
            'call_id' => 'call-boot-001',
            'user_id' => $agent->id,
            'alert_type' => BonvoiceCallAlertType::UnknownCaller,
            'customer_phone' => '9111555666',
            'order_id' => null,
            'incident_id' => null,
            'notified_at' => now(),
        ]);

        $result = app(ConversationWorkspaceBootstrapService::class)
            ->ensureIncidentForUnknownAnsweredCall($alert, $event, $agent);

        $this->assertNotNull($result);
        $this->assertNotNull($result->incident_id);
        $this->assertNotNull($result->order_id);

        $order = Order::query()->find($result->order_id);
        $this->assertTrue($order?->isInquiryOrder());

        $this->assertDatabaseHas('conversation_workspace_sessions', [
            'incident_id' => $result->incident_id,
            'call_id' => 'call-boot-001',
        ]);
        $this->assertSame(1, ConversationWorkspaceSession::query()->count());
    }
}
