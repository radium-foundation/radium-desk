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

class DashboardTeamActivityExpandTest extends TestCase
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

    public function test_ira_row_expands_and_has_no_badge_or_attendance_labels(): void
    {
        $agent = $this->createTrackedAgent();
        $this->createAssignmentAudit($agent, now()->subMinute());
        $this->createIraAudit(ServiceCaseAutomationMonitorService::EVENT_VALIDATION_PASSED, now());

        $iraId = (int) config('dashboard-team-activity.ira_agent_id', 0);
        $html = $this->panelHtml(expanded: [$iraId]);

        $this->assertStringContainsString('data-team-activity-agent="'.$iraId.'"', $html);
        $this->assertStringContainsString('is-expanded', $html);
        $this->assertStringContainsString('team-activity-kpi-table', $html);
        $this->assertStringNotContainsString('AI / Automation', $html);
        $this->assertStringNotContainsString('team-activity-badge', $html);
        $this->assertStringNotContainsString('Shift starts', $html);
        $this->assertStringNotContainsString('Logged In', $html);
        $this->assertStringNotContainsString('Logged Out', $html);
    }

    public function test_expanded_history_count_matches_today_kpi_count(): void
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
            'new_values' => ['incident_id' => $incident->id],
            'created_at' => now()->addSecond(),
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
            'created_at' => now()->addSeconds(2),
        ]);

        app(RemarkService::class)->createSystemRemarkForRemarkable(
            remarkable: $incident,
            actor: $agent,
            body: 'Sent driver installation instructions to the customer.',
            systemSource: RemarkSystemSource::WHATSAPP_DISPATCH,
        );

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'whatsapp.template_sent',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'new_values' => [
                'template_key' => 'follow_up',
                'trigger_source' => WhatsAppTemplateTriggerSource::Manual->value,
            ],
            'created_at' => now()->addSeconds(3),
        ]);

        app(RemarkService::class)->createForRemarkable(
            remarkable: $incident,
            actor: $agent,
            body: 'Customer confirmed callback time.',
        );

        $panel = app(TeamActivityPanelService::class)->build([$agent->id]);
        $row = collect($panel->agents)->firstWhere('id', $agent->id);

        $this->assertNotNull($row);
        $this->assertSame(3, $row->todayCount);
        $this->assertCount(3, $row->history);

        $html = $this->panelHtml(expanded: [$agent->id]);

        $this->assertStringContainsString('WhatsApp Sent', $html);
        $this->assertStringContainsString('Remark Added', $html);
        $this->assertStringContainsString('Status Changed', $html);
        $this->assertStringNotContainsString('Driver Guide Sent', $html);
        $this->assertStringNotContainsString('driver_installation_guide', $html);
        $this->assertStringNotContainsString('Sent driver installation instructions to the customer.', $html);
    }

    public function test_ira_expanded_history_count_matches_today_kpi_count(): void
    {
        $agent = $this->createTrackedAgent();
        $this->createAssignmentAudit($agent, now()->subMinute());

        $owner = User::factory()->create();
        [$incidentA] = $this->createIncident($owner);
        [$incidentB] = $this->createIncident($owner);

        foreach ([$incidentA, $incidentB] as $index => $incident) {
            AuditLog::query()->create([
                'user_id' => $owner->id,
                'event' => ServiceCaseAutomationMonitorService::EVENT_VALIDATION_PASSED,
                'auditable_type' => $incident->getMorphClass(),
                'auditable_id' => $incident->id,
                'created_at' => now()->subMinutes(2 - $index),
            ]);

            AuditLog::query()->create([
                'user_id' => $owner->id,
                'event' => ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED,
                'auditable_type' => $incident->getMorphClass(),
                'auditable_id' => $incident->id,
                'created_at' => now()->subMinutes(3 - $index),
            ]);
        }

        $iraId = (int) config('dashboard-team-activity.ira_agent_id', 0);
        $panel = app(TeamActivityPanelService::class)->build([$iraId]);
        $ira = collect($panel->agents)->firstWhere('isVirtual', true);

        $this->assertNotNull($ira);
        $this->assertSame(2, $ira->todayCount);
        $this->assertCount(2, $ira->history);
    }

    public function test_excluded_notification_events_never_appear_in_expanded_history(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident] = $this->createIncident($agent);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'notification.dispatched',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => now()->subMinutes(2),
        ]);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'communication_action.lifecycle',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => now()->subMinute(),
        ]);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'service_case.escalated',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => now(),
        ]);

        $panel = app(TeamActivityPanelService::class)->build([$agent->id]);
        $row = collect($panel->agents)->firstWhere('id', $agent->id);

        $this->assertNotNull($row);
        $this->assertSame(1, $row->todayCount);
        $this->assertCount(1, $row->history);
        $this->assertStringStartsWith('Escalated', $row->history[0]->label);
    }

    /**
     * @param  list<int>  $expanded
     */
    private function panelHtml(array $expanded = []): string
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return (string) $this->actingAs($viewer)
            ->getJson(route('dashboard.team-activity', ['expanded' => $expanded]))
            ->assertOk()
            ->json('html');
    }

    private function createAssignmentAudit(User $user, Carbon $createdAt): void
    {
        [$incident] = $this->createIncident($user);

        $audit = AuditLog::query()->create([
            'user_id' => $user->id,
            'event' => 'service_case.assigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);
        $audit->forceFill(['created_at' => $createdAt])->saveQuietly();
    }

    private function createIraAudit(string $event, Carbon $createdAt): void
    {
        $user = User::factory()->create();
        [$incident] = $this->createIncident($user);

        $audit = AuditLog::query()->create([
            'user_id' => $user->id,
            'event' => $event,
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);
        $audit->forceFill(['created_at' => $createdAt])->saveQuietly();
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
            'serial_number' => 'SN-EXPAND',
            'customer_name' => 'Expand Test Customer',
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
            'title' => 'Expand test case',
            'description' => 'Expand test case.',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        return [$incident, $order];
    }
}
