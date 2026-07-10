<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseCloseExceptionReason;
use App\Enums\WaitingReason;
use App\Enums\WorkspaceActionType;
use App\Enums\WorkspaceContext;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\Operations\SmartAssignmentService;
use App\Services\ServiceCaseCloseRequirementService;
use App\Services\SystemSettingsService;
use App\Services\Dashboard\DashboardSnapshotStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SerialCorrectionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'interakt.api_key' => 'test-interakt-key',
            'interakt.base_url' => 'https://api.interakt.ai',
            'interakt.templates.request_correct_serial.name' => 'order_update_request_correct_serial',
            'interakt.templates.request_correct_serial.language_code' => 'en',
        ]);

        $this->seed(RolePermissionSeeder::class);

        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin']);
    }

    public function test_request_correct_serial_creates_serial_waiting_state_with_correction_metadata(): void
    {
        [$agent, $incident] = $this->createInvalidSerialIncident();
        $this->enableNotificationChannels();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-correct-serial-001'], 200),
        ]);

        $this->actingAs($agent)->postJson(
            route('incidents.workspace.request-correct-serial', $incident),
            ['workspace_context' => 'customer'],
        )->assertOk()
            ->assertJsonPath('success', true);

        $waitingState = IncidentWaitingState::query()
            ->where('incident_id', $incident->id)
            ->first();

        $this->assertNotNull($waitingState);
        $this->assertSame(WaitingReason::SerialNumber, $waitingState->waiting_reason);
        $this->assertTrue($waitingState->sla_paused);
        $this->assertNull($waitingState->cleared_at);
        $this->assertTrue($waitingState->metadata['serial_correction'] ?? false);
        $this->assertSame('54SAXXC5514586', $waitingState->metadata['old_serial'] ?? null);
        $this->assertSame($agent->id, $incident->fresh()->assigned_to_user_id);
    }

    public function test_wrong_serial_case_is_removed_from_agent_active_workload_after_request(): void
    {
        [$agent, $incident] = $this->createInvalidSerialIncident();
        $this->enableNotificationChannels();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-correct-serial-002'], 200),
        ]);

        $metricsBefore = app(SmartAssignmentService::class)->workloadMetrics($agent);
        $this->assertSame(1, $metricsBefore['open_cases']);

        $this->actingAs($agent)->postJson(
            route('incidents.workspace.request-correct-serial', $incident),
            ['workspace_context' => 'customer'],
        )->assertOk();

        app(DashboardSnapshotStore::class)->forget();

        $freshIncident = $incident->fresh(['activeWaitingState', 'order', 'supportAppointments', 'assignee']);
        $classifier = app(OperationsQueueClassifier::class);

        $this->assertTrue($classifier->isWaitingCustomer($freshIncident));
        $this->assertSame(0, app(SmartAssignmentService::class)->workloadMetrics($agent)['open_cases']);
    }

    public function test_wrong_serial_blocks_normal_close_but_allows_exception_close(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        [, $incident] = $this->createInvalidSerialIncident($admin);

        $messages = app(ServiceCaseCloseRequirementService::class)->validate(
            incident: $incident->load('order'),
            serialNumberUnavailable: false,
            referenceNumberUnavailable: false,
        );

        $this->assertSame(
            'Serial number must be verified or corrected before closing this service case.',
            $messages['serial_number'] ?? null,
        );

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'body' => 'Attempting close with suspicious serial.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Serial number must be verified or corrected before closing this service case.');

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'body' => 'Closing with documented serial exception.',
                'serial_number_unavailable' => true,
                'serial_exception_reason' => ServiceCaseCloseExceptionReason::ApprovedByAdmin->value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
    }

    public function test_customer_not_responding_close_is_blocked_before_follow_up_is_sent(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [, $incident] = $this->createInvalidSerialIncident($agent);

        IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now(),
            'sla_paused' => true,
            'reminder_policy_key' => 'customer_waiting_default',
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'body' => 'Customer not responding.',
                'serial_number_unavailable' => true,
                'serial_exception_reason' => ServiceCaseCloseExceptionReason::CustomerNotResponding->value,
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Send the customer follow-up and wait for the response window before closing as customer not responding.',
            );

        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
    }

    public function test_customer_not_responding_close_is_allowed_after_follow_up_is_sent(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [, $incident] = $this->createInvalidSerialIncident($agent);
        $incident->order?->update(['transaction_id' => 'TXN-CLOSE-TEST']);

        IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now()->subDay(),
            'customer_followup_sent_at' => now()->subHours(6),
            'sla_paused' => true,
            'reminder_policy_key' => 'customer_waiting_default',
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'body' => 'Customer not responding after follow-up.',
                'serial_number_unavailable' => true,
                'serial_exception_reason' => ServiceCaseCloseExceptionReason::CustomerNotResponding->value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createInvalidSerialIncident(?User $agent = null): array
    {
        $agent ??= tap(User::factory()->create(), function (User $user): void {
            $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        });

        $order = Order::query()->create([
            'order_id' => 'RD-CORRECT-INVALID',
            'serial_number' => '54SAXXC5514586',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Invalid Serial Customer',
            'customer_email' => 'invalid@example.com',
            'customer_phone' => '9123456782',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Invalid serial case',
            'description' => 'Bad serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$agent, $incident];
    }

    private function enableNotificationChannels(): void
    {
        foreach ([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => true,
            'whatsapp.api_enabled' => true,
            'email.api_enabled' => true,
        ] as $key => $enabled) {
            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $enabled ? '1' : '0'],
            );
            app(SystemSettingsService::class)->forget($key);
        }
    }
}
