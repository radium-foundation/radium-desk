<?php

namespace Tests\Feature;

use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationPolicyActionType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WaitingReason;
use App\Models\AutomationExecution;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationHealthDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_agent_cannot_access_automation_health_dashboard(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('admin.operations.automation-health'))
            ->assertForbidden();
    }

    public function test_admin_can_view_automation_health_dashboard(): void
    {
        $admin = $this->createAdminUser();
        $this->seedSuccessfulWaitingExecution();

        $this->actingAs($admin)
            ->get(route('admin.operations.automation-health'))
            ->assertOk()
            ->assertSee('Automation Health')
            ->assertSee('Overview')
            ->assertSee('Automation Breakdown')
            ->assertSee('Waiting Lifecycle')
            ->assertSee('Appointment Reminder')
            ->assertSee('Recent Activity')
            ->assertSee('Failures');
    }

    public function test_operations_manager_can_view_automation_health_dashboard(): void
    {
        $operationsAdmin = User::factory()->create(['is_active' => true]);
        $operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $this->actingAs($operationsAdmin)
            ->get(route('admin.operations.automation-health'))
            ->assertOk()
            ->assertSee('Automation Health');
    }

    public function test_dashboard_shows_healthy_status_when_recent_success_and_no_failures_today(): void
    {
        $admin = $this->createAdminUser();
        $this->seedSuccessfulWaitingExecution();

        $this->actingAs($admin)
            ->get(route('admin.operations.automation-health'))
            ->assertOk()
            ->assertSee('Healthy');
    }

    public function test_dashboard_shows_warning_status_when_failures_exist_today(): void
    {
        $admin = $this->createAdminUser();
        $waitingState = $this->createWaitingState();

        AutomationExecution::query()->create([
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'request_serial',
            'schedule_step' => 1,
            'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
            'action_key' => 'request_serial_number',
            'channel' => 'whatsapp',
            'status' => AutomationExecutionStatus::Failed,
            'idempotency_key' => 'automation.health.failed.1',
            'error_message' => 'Channel timeout',
            'started_at' => now()->subMinutes(10),
            'completed_at' => now()->subMinutes(10),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.operations.automation-health'))
            ->assertOk()
            ->assertSee('Warning')
            ->assertSee('Channel timeout');
    }

    public function test_dashboard_shows_failed_status_when_scheduler_is_stalled(): void
    {
        config(['operations.automation_health.stall_threshold_minutes' => 30]);

        $admin = $this->createAdminUser();
        $waitingState = $this->createWaitingState();

        AutomationExecution::query()->create([
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'request_serial',
            'schedule_step' => 1,
            'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
            'action_key' => 'request_serial_number',
            'channel' => 'whatsapp',
            'status' => AutomationExecutionStatus::Success,
            'idempotency_key' => 'automation.health.stale.1',
            'started_at' => now()->subHours(3),
            'completed_at' => now()->subHours(3),
        ])->forceFill(['created_at' => now()->subHours(3)])->save();

        $this->actingAs($admin)
            ->get(route('admin.operations.automation-health'))
            ->assertOk()
            ->assertSee('Scheduler appears stalled');
    }

    public function test_activity_can_be_filtered_by_status_and_automation_type(): void
    {
        $admin = $this->createAdminUser();
        $waitingState = $this->createWaitingState();

        AutomationExecution::query()->create([
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'request_serial',
            'schedule_step' => 1,
            'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
            'action_key' => 'request_serial_number',
            'channel' => 'whatsapp',
            'status' => AutomationExecutionStatus::Success,
            'idempotency_key' => 'automation.health.filter.success',
            'started_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
        ]);

        AutomationExecution::query()->create([
            'policy_key' => 'appointment-reminder',
            'schedule_step' => 30,
            'action_type' => AutomationPolicyActionType::AppointmentReminderTelegram,
            'action_key' => 'appointment_reminder',
            'channel' => 'telegram',
            'status' => AutomationExecutionStatus::Failed,
            'idempotency_key' => 'automation.health.filter.failed',
            'error_message' => 'Telegram unavailable',
            'started_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
        ]);

        $filteredResponse = $this->actingAs($admin)
            ->get(route('admin.operations.automation-health', [
                'automation_type' => 'waiting_lifecycle',
                'status' => 'success',
            ]));

        $filteredResponse->assertOk()
            ->assertSee('request_serial_number')
            ->assertSee('Waiting Lifecycle', false);

        $this->actingAs($admin)
            ->get(route('admin.operations.automation-health', [
                'search' => 'automation.health.filter.failed',
            ]))
            ->assertOk()
            ->assertSee('Telegram unavailable');
    }

    public function test_execution_detail_endpoint_returns_drawer_payload(): void
    {
        $admin = $this->createAdminUser();
        $execution = $this->seedSuccessfulWaitingExecution();

        $this->actingAs($admin)
            ->getJson(route('admin.operations.automation-health.executions.show', $execution))
            ->assertOk()
            ->assertJsonPath('execution.id', $execution->id)
            ->assertJsonPath('execution.policy_key', 'request_serial')
            ->assertJsonPath('execution.status', AutomationExecutionStatus::Success->value)
            ->assertJsonPath('execution.retry_status', 'Completed');
    }

    public function test_failures_section_lists_only_failed_executions(): void
    {
        $admin = $this->createAdminUser();
        $waitingState = $this->createWaitingState();

        AutomationExecution::query()->create([
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'request_serial',
            'schedule_step' => 1,
            'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
            'action_key' => 'request_serial_number',
            'channel' => 'whatsapp',
            'status' => AutomationExecutionStatus::Success,
            'idempotency_key' => 'automation.health.failures.success',
            'started_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
        ]);

        AutomationExecution::query()->create([
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'request_serial',
            'schedule_step' => 2,
            'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
            'action_key' => 'request_serial_number_reminder',
            'channel' => 'whatsapp',
            'status' => AutomationExecutionStatus::Failed,
            'idempotency_key' => 'automation.health.failures.failed',
            'error_message' => 'Template rejected',
            'started_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.operations.automation-health'))
            ->assertOk()
            ->assertSee('Template rejected')
            ->assertSee('Will retry on next scheduler run');
    }

    private function createAdminUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createWaitingState(): IncidentWaitingState
    {
        $actor = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-HEALTH-001',
            'customer_name' => 'Health Customer',
            'serial_number' => 'FPSPL1141XX',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Health test incident',
            'description' => 'Automation health test case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        return IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now()->subHour(),
            'sla_paused' => true,
            'reminder_policy_key' => 'request_serial',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function seedSuccessfulWaitingExecution(): AutomationExecution
    {
        $waitingState = $this->createWaitingState();

        return AutomationExecution::query()->create([
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'request_serial',
            'schedule_step' => 1,
            'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
            'action_key' => 'request_serial_number',
            'channel' => 'whatsapp',
            'status' => AutomationExecutionStatus::Success,
            'idempotency_key' => 'automation.health.success.1',
            'started_at' => now()->subMinutes(2),
            'completed_at' => now()->subMinutes(2),
            'metadata' => [
                'channel_results' => [
                    ['channel' => 'whatsapp', 'success' => true],
                ],
            ],
        ]);
    }
}
