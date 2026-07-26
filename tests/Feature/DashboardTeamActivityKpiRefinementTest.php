<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Remark;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\CommunicationActions\ReferenceNumberCommunicationService;
use App\Services\Dashboard\TeamActivityPanelService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\PresenceEngineService;
use App\Services\RemarkService;
use App\Services\ServiceCaseAutomationMonitorService;
use App\Support\Remarks\RemarkSystemSource;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTeamActivityKpiRefinementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['dashboard-team-activity.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-07-06 11:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_driver_guide_sent_does_not_increase_kpi(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident, $order] = $this->createIncident($agent);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => ReferenceNumberCommunicationService::IDEMPOTENCY_AUDIT_EVENT,
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'new_values' => [
                'incident_id' => $incident->id,
                'communication_action_key' => 'driver_installation_guide',
            ],
            'created_at' => now(),
        ]);

        $this->assertSame(0, $this->todayCountFor($agent));
    }

    public function test_auto_whatsapp_does_not_increase_kpi(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident] = $this->createIncident($agent);

        foreach ([
            WhatsAppTemplateTriggerSource::Automation->value,
            WhatsAppTemplateTriggerSource::Scheduler->value,
            WhatsAppTemplateTriggerSource::Ira->value,
            WhatsAppTemplateTriggerSource::Webhook->value,
        ] as $index => $triggerSource) {
            AuditLog::query()->create([
                'user_id' => $agent->id,
                'event' => 'whatsapp.template_sent',
                'auditable_type' => $incident->getMorphClass(),
                'auditable_id' => $incident->id,
                'new_values' => [
                    'template_key' => 'driver_installation_guide',
                    'trigger_source' => $triggerSource,
                ],
                'created_at' => now()->subSeconds($index),
            ]);
        }

        $this->assertSame(0, $this->todayCountFor($agent));
    }

    public function test_manual_whatsapp_still_counts(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident] = $this->createIncident($agent);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'whatsapp.template_sent',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'new_values' => [
                'template_key' => 'follow_up',
                'trigger_source' => WhatsAppTemplateTriggerSource::Manual->value,
            ],
            'created_at' => now(),
        ]);

        $this->assertSame(1, $this->todayCountFor($agent));
    }

    public function test_manual_remarks_still_count(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident] = $this->createIncident($agent);

        app(RemarkService::class)->createForRemarkable(
            remarkable: $incident,
            actor: $agent,
            body: 'Customer confirmed pickup window.',
        );

        $this->assertSame(1, $this->todayCountFor($agent));
    }

    public function test_null_origin_and_system_remarks_do_not_count(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident] = $this->createIncident($agent);

        $nullOrigin = Remark::query()->create([
            'user_id' => $agent->id,
            'remarkable_type' => $incident->getMorphClass(),
            'remarkable_id' => $incident->id,
            'body' => 'Sent driver installation instructions to the customer.',
        ]);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'created',
            'auditable_type' => $nullOrigin->getMorphClass(),
            'auditable_id' => $nullOrigin->id,
            'new_values' => [
                'body' => $nullOrigin->body,
            ],
            'created_at' => now(),
        ]);

        app(RemarkService::class)->createSystemRemarkForRemarkable(
            remarkable: $incident,
            actor: $agent,
            body: 'System workflow note.',
            systemSource: RemarkSystemSource::WHATSAPP_DISPATCH,
        );

        $this->assertSame(0, $this->todayCountFor($agent));
    }

    public function test_one_completed_service_reference_workflow_equals_one_kpi(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident, $order] = $this->createIncident($agent);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'service_case.status_changed',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'new_values' => ['status' => IncidentStatus::Closed->value],
            'created_at' => now(),
        ]);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => ReferenceNumberCommunicationService::IDEMPOTENCY_AUDIT_EVENT,
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'new_values' => [
                'incident_id' => $incident->id,
                'communication_action_key' => 'driver_installation_guide',
                'automation_trigger' => 'reference_number_added',
            ],
            'created_at' => now()->addSeconds(2),
        ]);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'whatsapp.template_sent',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'new_values' => [
                'template_key' => 'driver_installation_guide',
                'trigger_source' => WhatsAppTemplateTriggerSource::Automation->value,
            ],
            'created_at' => now()->addSeconds(3),
        ]);

        app(RemarkService::class)->createSystemRemarkForRemarkable(
            remarkable: $incident,
            actor: $agent,
            body: 'Sent driver installation instructions to the customer.',
            systemSource: RemarkSystemSource::WHATSAPP_DISPATCH,
        );

        // Untagged legacy companion note (production shape before origin tagging).
        $legacyNote = Remark::query()->create([
            'user_id' => $agent->id,
            'remarkable_type' => $incident->getMorphClass(),
            'remarkable_id' => $incident->id,
            'body' => 'Sent driver installation instructions to the customer.',
        ]);
        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'created',
            'auditable_type' => $legacyNote->getMorphClass(),
            'auditable_id' => $legacyNote->id,
            'new_values' => ['body' => $legacyNote->body],
            'created_at' => now()->addSeconds(4),
        ]);

        $this->assertSame(1, $this->todayCountFor($agent));
    }

    public function test_ira_counts_completed_incidents_not_pipeline_stages(): void
    {
        $this->createTrackedAgent();
        $owner = User::factory()->create();
        [$incidentA] = $this->createIncident($owner);
        [$incidentB] = $this->createIncident($owner);

        $pipeline = [
            'service_case.automation_pending',
            ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED,
            ServiceCaseAutomationMonitorService::EVENT_WAITING_RADIUMBOX,
            ServiceCaseAutomationMonitorService::EVENT_RADIUMBOX_VERIFIED,
            ServiceCaseAutomationMonitorService::EVENT_VALIDATION_PASSED,
        ];

        foreach ($pipeline as $index => $event) {
            AuditLog::query()->create([
                'user_id' => $owner->id,
                'event' => $event,
                'auditable_type' => $incidentA->getMorphClass(),
                'auditable_id' => $incidentA->id,
                'created_at' => now()->subMinutes(10 - $index),
            ]);
        }

        // Incomplete pipeline on another incident — must not count.
        foreach ([
            'service_case.automation_pending',
            ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED,
            ServiceCaseAutomationMonitorService::EVENT_WAITING_RADIUMBOX,
            ServiceCaseAutomationMonitorService::EVENT_RADIUMBOX_VERIFIED,
        ] as $index => $event) {
            AuditLog::query()->create([
                'user_id' => $owner->id,
                'event' => $event,
                'auditable_type' => $incidentB->getMorphClass(),
                'auditable_id' => $incidentB->id,
                'created_at' => now()->subMinutes(5 - $index),
            ]);
        }

        // Duplicate completion audit for same incident must still count once.
        AuditLog::query()->create([
            'user_id' => $owner->id,
            'event' => ServiceCaseAutomationMonitorService::EVENT_VALIDATION_PASSED,
            'auditable_type' => $incidentA->getMorphClass(),
            'auditable_id' => $incidentA->id,
            'created_at' => now(),
        ]);

        $ira = collect(app(TeamActivityPanelService::class)->build()->agents)
            ->firstWhere('isVirtual', true);

        $this->assertNotNull($ira);
        $this->assertSame(1, $ira->todayCount);
    }

    public function test_manual_email_promote_counts_auto_link_does_not(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident] = $this->createIncident($agent);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'incoming_email.linked',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => now()->subMinute(),
        ]);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'incoming_email.promoted_to_service_case',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => now(),
        ]);

        $this->assertSame(1, $this->todayCountFor($agent));
    }

    private function todayCountFor(User $agent): int
    {
        $panel = app(TeamActivityPanelService::class)->build();
        $row = collect($panel->agents)->firstWhere('id', $agent->id);

        return $row?->todayCount ?? 0;
    }

    private function createTrackedAgent(string $name = 'Tracked Agent'): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        app(PresenceEngineService::class)->startSession($user->fresh(['workSchedule', 'roles']));

        return $user->fresh(['workSchedule', 'roles']);
    }

    /**
     * @return array{0: Incident, 1: Order}
     */
    private function createIncident(User $user): array
    {
        $order = Order::query()->create([
            'order_id' => 'RD'.random_int(1000000, 9999999),
            'serial_number' => 'SN-KPI',
            'customer_name' => 'KPI Customer',
            'product_name' => 'RBX 110',
            'device_model' => 'RBX 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'KPI refinement test case',
            'description' => 'KPI refinement test case.',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        return [$incident, $order];
    }
}
