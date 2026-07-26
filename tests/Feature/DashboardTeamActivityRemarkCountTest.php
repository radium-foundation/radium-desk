<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RemarkOrigin;
use App\Enums\TeamAvailabilityStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Remark;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\Dashboard\TeamActivityPanelService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\PresenceEngineService;
use App\Services\RemarkService;
use App\Services\ServiceCaseActionRemarkService;
use App\Support\Remarks\RemarkSystemSource;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTeamActivityRemarkCountTest extends TestCase
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

    public function test_manual_remark_counts_as_one_operational_activity(): void
    {
        $agent = $this->createTrackedAgent();
        [$incident] = $this->createIncident($agent);

        app(RemarkService::class)->createForRemarkable(
            remarkable: $incident,
            actor: $agent,
            body: 'Customer confirmed replacement address.',
        );

        $this->assertSame(1, $this->todayCountFor($agent));
    }

    public function test_system_assignment_remark_does_not_count_toward_kpi(): void
    {
        $actor = $this->createTrackedAdmin();
        $assignee = $this->createTrackedAgent('Assignee Agent');
        [$incident] = $this->createIncident($actor);

        app(RemarkService::class)->createSystemRemarkForRemarkable(
            remarkable: $incident,
            actor: $actor,
            body: 'Assigning for follow-up.',
            systemSource: RemarkSystemSource::WORKSPACE_ASSIGN,
        );

        AuditLog::query()->create([
            'user_id' => $actor->id,
            'event' => 'service_case.reassigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'new_values' => ['assigned_to_user_id' => $assignee->id],
        ]);

        $this->assertSame(1, $this->todayCountFor($actor));
    }

    public function test_system_close_remark_does_not_count_toward_kpi(): void
    {
        $actor = $this->createTrackedAdmin();
        [$incident] = $this->createIncident($actor);

        app(RemarkService::class)->createSystemRemarkForRemarkable(
            remarkable: $incident,
            actor: $actor,
            body: 'Closing after customer confirmation.',
            systemSource: RemarkSystemSource::WORKSPACE_CLOSE,
        );

        AuditLog::query()->create([
            'user_id' => $actor->id,
            'event' => 'service_case.status_changed',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'new_values' => ['status' => IncidentStatus::Closed->value],
        ]);

        $this->assertSame(1, $this->todayCountFor($actor));
    }

    public function test_system_whatsapp_remark_and_auto_whatsapp_do_not_count_toward_kpi(): void
    {
        $actor = $this->createTrackedAdmin();
        [$incident] = $this->createIncident($actor);

        app(RemarkService::class)->createSystemRemarkForRemarkable(
            remarkable: $incident,
            actor: $actor,
            body: 'WhatsApp template sent to customer.',
            systemSource: RemarkSystemSource::WHATSAPP_DISPATCH,
        );

        AuditLog::query()->create([
            'user_id' => $actor->id,
            'event' => 'whatsapp.template_sent',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'new_values' => [
                'template_key' => 'driver_installation_guide',
                'trigger_source' => 'automation',
            ],
        ]);

        $this->assertSame(0, $this->todayCountFor($actor));
    }

    public function test_system_remarks_remain_in_audit_history(): void
    {
        $actor = $this->createTrackedAdmin();
        [$incident] = $this->createIncident($actor);

        $remark = app(RemarkService::class)->createSystemRemarkForRemarkable(
            remarkable: $incident,
            actor: $actor,
            body: 'System workflow note.',
            systemSource: RemarkSystemSource::STATUS_CHANGE,
        );

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->id,
            'event' => 'created',
            'auditable_type' => $remark->getMorphClass(),
            'auditable_id' => $remark->id,
        ]);

        $audit = AuditLog::query()
            ->where('auditable_id', $remark->id)
            ->where('event', 'created')
            ->first();

        $this->assertSame(RemarkOrigin::System->value, $audit?->new_values['origin'] ?? null);
        $this->assertSame(RemarkSystemSource::STATUS_CHANGE, $audit?->new_values['system_source'] ?? null);
        $this->assertSame(RemarkOrigin::System, $remark->fresh()->metadataDto()->origin);
    }

    public function test_status_change_service_marks_companion_remark_as_system(): void
    {
        $actor = $this->createTrackedAdmin();
        [$incident] = $this->createIncident($actor);

        app(ServiceCaseActionRemarkService::class)->execute(
            incident: $incident,
            actor: $actor,
            status: IncidentStatus::Resolved,
            body: 'Resolved after callback.',
        );

        $remark = Remark::query()->firstOrFail();

        $this->assertSame(RemarkOrigin::System, $remark->metadataDto()->origin);
        $this->assertSame(RemarkSystemSource::STATUS_CHANGE, $remark->metadataDto()->systemSource);
        $this->assertSame(1, $this->todayCountFor($actor));
    }

    private function todayCountFor(User $agent): int
    {
        $panel = app(TeamActivityPanelService::class)->build();
        $row = collect($panel->agents)->firstWhere('id', $agent->id);

        return $row?->todayCount ?? 0;
    }

    private function createTrackedAgent(string $name = 'Tracked Agent', bool $startSession = true): User
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

        if ($startSession) {
            app(PresenceEngineService::class)->startSession($user->fresh(['workSchedule', 'roles']));
        }

        return $user->fresh(['workSchedule', 'roles']);
    }

    private function createTrackedAdmin(): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

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
            'serial_number' => 'SN-REMARK-KPI',
            'customer_name' => 'Remark KPI Customer',
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
            'title' => 'Remark KPI test case',
            'description' => 'Remark KPI test case.',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        return [$incident, $order];
    }
}
