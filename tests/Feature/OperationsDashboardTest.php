<?php

namespace Tests\Feature;

use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationPolicyActionType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WaitingReason;
use App\Models\AuditLog;
use App\Models\AutomationExecution;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\NotificationAuditTrailService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    private function createAdminUser(string $email = 'admin-ops-dashboard@test.com'): User
    {
        $user = User::factory()->create([
            'name' => 'Ops Admin',
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createAgentUser(string $email = 'agent-ops-dashboard@test.com'): User
    {
        $user = User::factory()->create([
            'name' => 'Ops Agent',
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    public function test_agent_cannot_access_operations_dashboard(): void
    {
        $this->actingAs($this->createAgentUser())
            ->get(route('admin.operations.index'))
            ->assertForbidden();
    }

    public function test_admin_can_view_operations_dashboard(): void
    {
        $admin = $this->createAdminUser();
        $actor = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RD-OPS-001',
            'customer_name' => 'Jane Customer',
            'serial_number' => 'FPSPL1141XX',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Notification failure case',
            'description' => 'Test case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);

        app(AuditLogService::class)->log(
            userId: $actor->id,
            event: NotificationAuditTrailService::EVENT_DISPATCHED,
            auditable: $incident,
            newValues: [
                'notification_type' => 'request_serial_number',
                'source' => 'manual',
                'trigger_source' => 'manual',
                'aggregate_success' => false,
                'aggregate_message' => 'Notification failed',
                'channel_results' => [
                    [
                        'channel' => 'whatsapp',
                        'status' => 'failed',
                        'success' => false,
                        'retryable' => true,
                        'message' => 'Interakt API timeout',
                        'timestamp' => now()->toIso8601String(),
                        'duration_ms' => 1200,
                    ],
                ],
            ],
        );

        $waitingState = IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now()->subHour(),
            'sla_paused' => true,
            'reminder_policy_key' => 'request_serial',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        AutomationExecution::query()->create([
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'request_serial',
            'schedule_step' => 1,
            'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
            'action_key' => 'request_serial_number',
            'channel' => 'whatsapp',
            'status' => AutomationExecutionStatus::Success,
            'idempotency_key' => 'automation.test.1',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now()->subMinutes(5)->addSeconds(2),
            'metadata' => [
                'channel_results' => [
                    ['channel' => 'whatsapp', 'success' => true],
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->assertSee('Operations Control Center')
            ->assertSee('IRA Advisor')
            ->assertSee('Recommendations only')
            ->assertSee('System Health')
            ->assertSee('Notification Metrics')
            ->assertSee('Automation Metrics')
            ->assertSee('Queue Metrics')
            ->assertSee('Integration Health')
            ->assertSee('Recent Notification Failures')
            ->assertSee('Recent Automation Activity')
            ->assertSee('Interakt API timeout')
            ->assertSee('Jane Customer')
            ->assertSee($incident->display_reference)
            ->assertSee('Open Incident')
            ->assertSee('Automation Runtime')
            ->assertSee('Cashfree');
    }

    public function test_operations_dashboard_shows_interakt_template_configuration_health(): void
    {
        config(['interakt.api_key' => 'test-interakt-key']);

        foreach ([
            'request_serial_number' => 'order_confirm_manual_schedule',
            'repair_started' => 'repair_started',
            'repair_completed' => 'repair_completed',
            'ready_for_dispatch' => 'ready_for_dispatch',
            'refund_update' => 'refund_update',
            'amc_reminder' => 'amc_reminder',
        ] as $templateKey => $templateName) {
            config([
                'interakt.templates.'.$templateKey.'.name' => $templateName,
                'interakt.templates.'.$templateKey.'.language_code' => 'en_US',
                'interakt.templates.'.$templateKey.'.language_code_is_default' => false,
            ]);
        }

        $this->actingAs($this->createAdminUser('admin-ops-templates@test.com'))
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->assertSee('Interakt Template Configuration', false)
            ->assertSee('6 / 6 templates configured', false);
    }

    public function test_operations_dashboard_shows_meta_flow_integration_card(): void
    {
        $this->actingAs($this->createAdminUser('admin-ops-meta-flow@test.com'))
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->assertSee('Meta Flow', false)
            ->assertSee('Not Configured', false);
    }

    public function test_admin_can_refresh_operations_dashboard_live_payload(): void
    {
        $admin = $this->createAdminUser('admin-ops-live@test.com');

        $response = $this->actingAs($admin)
            ->getJson(route('admin.operations.live'));

        $response->assertOk()
            ->assertJsonStructure([
                'generated_at',
                'html' => [
                    'advisor_insights',
                    'system_health',
                    'notification_metrics',
                    'automation_metrics',
                    'queue_metrics',
                    'integration_health',
                    'recent_notification_failures',
                    'recent_automation_activity',
                ],
            ]);
    }

    public function test_sidebar_shows_operations_link_for_admin(): void
    {
        $this->actingAs($this->createAdminUser('admin-ops-nav@test.com'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Operations')
            ->assertSee(route('admin.operations.index'), false);
    }
}
