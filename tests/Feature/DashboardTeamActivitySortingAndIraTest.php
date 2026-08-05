<?php

namespace Tests\Feature;

use App\Data\TeamActivityAgentRow;
use App\Enums\IncidentSource;
use App\Enums\TeamAvailabilityStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\CommunicationActions\CommunicationActionLifecycleAuditService;
use App\Services\Dashboard\TeamActivityPanelService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\PresenceEngineService;
use App\Services\ServiceCaseAutomationMonitorService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTeamActivitySortingAndIraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config([
            'dashboard-team-activity.enabled' => true,
            'cashfree.system_user_email' => 'ira-system@radium.local',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-06 11:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_team_members_are_sorted_by_recent_activity(): void
    {
        $recent = $this->createTrackedAgent('Shipra Agent', startSession: true);
        $middle = $this->createTrackedAgent('Rahul Agent', startSession: true);
        $oldest = $this->createTrackedAgent('Aman Agent', startSession: true);

        $this->createAssignmentAudit($recent, now()->subMinutes(2));
        $this->createAssignmentAudit($middle, now()->subMinutes(5));
        $this->createAssignmentAudit($oldest, now()->subMinutes(22));

        $names = $this->humanAgentNames(app(TeamActivityPanelService::class)->build());

        $this->assertSame(['Shipra Agent', 'Rahul Agent', 'Aman Agent'], $names);
    }

    public function test_tie_break_prefers_working_users_over_off_duty_users(): void
    {
        $working = $this->createTrackedAgent('Working Agent', startSession: true);
        $offDuty = $this->createTrackedAgent('Off Duty Agent');

        $sameTime = now()->subMinutes(10);
        $this->createAssignmentAudit($working, $sameTime);
        $this->createAssignmentAudit($offDuty, $sameTime);

        $names = $this->humanAgentNames(app(TeamActivityPanelService::class)->build());

        $this->assertSame(['Working Agent', 'Off Duty Agent'], $names);
    }

    public function test_ira_appears_exactly_once_as_virtual_member(): void
    {
        $agent = $this->createTrackedAgent('Human Agent', startSession: true);
        $this->createAssignmentAudit($agent, now()->subMinute());
        $this->createIraAudit(ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED, now()->subMinutes(3));

        $panel = app(TeamActivityPanelService::class)->build();
        $iraRows = array_values(array_filter(
            $panel->agents,
            static fn (TeamActivityAgentRow $row): bool => $row->isVirtual,
        ));

        $this->assertCount(1, $iraRows);
        $this->assertSame('IRA', $iraRows[0]->name);
        $this->assertNull($iraRows[0]->badge);
        $this->assertNull($iraRows[0]->workingLabel);
        $this->assertTrue($iraRows[0]->isVirtual);
    }

    public function test_ira_is_not_treated_as_attendance_user(): void
    {
        $systemUser = User::factory()->create([
            'email' => 'ira-system@radium.local',
            'is_active' => true,
            'name' => 'System User',
        ]);
        $systemUser->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $systemUser->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        $human = $this->createTrackedAgent('Human Agent', startSession: true);
        $this->createAssignmentAudit($human, now()->subMinute());

        $panel = app(TeamActivityPanelService::class)->build();
        $names = array_map(static fn (TeamActivityAgentRow $row): string => $row->name, $panel->agents);

        $this->assertNotContains('System User', $names);
        $this->assertContains('IRA', $names);
        $this->assertCount(1, array_filter($panel->agents, static fn ($row) => $row->name === 'IRA'));
    }

    public function test_ira_today_count_ignores_lifecycle_events(): void
    {
        $this->createTrackedAgent('Human Agent', startSession: true);
        [$incident] = $this->createIncident(User::factory()->create());

        foreach (range(1, 20) as $index) {
            AuditLog::query()->create([
                'user_id' => null,
                'event' => CommunicationActionLifecycleAuditService::EVENT,
                'auditable_type' => $incident->getMorphClass(),
                'auditable_id' => $incident->id,
                'created_at' => now()->subMinutes($index),
            ]);
        }

        $this->createIraAudit(ServiceCaseAutomationMonitorService::EVENT_VALIDATION_PASSED, now()->subMinutes(1));
        $this->createIraAudit(ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED, now());

        $panel = app(TeamActivityPanelService::class)->build();
        $ira = collect($panel->agents)->firstWhere('isVirtual', true);

        $this->assertNotNull($ira);
        // Pipeline stages do not inflate KPI — only distinct completed incidents count.
        $this->assertSame(1, $ira->todayCount);
    }

    public function test_ira_is_positioned_after_active_humans_and_before_off_duty_humans(): void
    {
        $active = $this->createTrackedAgent('Active Agent', startSession: true);
        $offDuty = $this->createTrackedAgent('Off Duty Agent');

        $this->createAssignmentAudit($active, now()->subMinute());
        $this->createIraAudit(ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED, now()->subMinutes(2));

        $names = array_map(
            static fn (TeamActivityAgentRow $row): string => $row->name,
            app(TeamActivityPanelService::class)->build()->agents,
        );

        $this->assertSame(['Active Agent', 'IRA', 'Off Duty Agent'], $names);
    }

    public function test_panel_build_query_count_stays_bounded(): void
    {
        foreach (['Alpha Agent', 'Beta Agent', 'Gamma Agent'] as $name) {
            $agent = $this->createTrackedAgent($name, startSession: true);
            $this->createAssignmentAudit($agent, now()->subMinutes(random_int(1, 30)));
        }

        $this->createIraAudit(ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED, now());

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(TeamActivityPanelService::class)->build();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Keep the measured count in the assertion message for budget investigations.
        $this->assertLessThanOrEqual(
            120,
            $queryCount,
            "Team Activity build should not introduce unbounded query growth (actual={$queryCount}).",
        );
    }

    /**
     * @return list<string>
     */
    private function humanAgentNames(\App\Data\TeamActivityPanel $panel): array
    {
        return array_values(array_map(
            static fn (TeamActivityAgentRow $row): string => $row->name,
            array_filter(
                $panel->agents,
                static fn (TeamActivityAgentRow $row): bool => ! $row->isVirtual,
            ),
        ));
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

    private function createTrackedAgent(string $name, bool $startSession = false): User
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

    /**
     * @return array{0: Incident, 1: Order}
     */
    private function createIncident(User $user): array
    {
        $order = Order::query()->create([
            'order_id' => 'RD'.random_int(1000000, 9999999),
            'serial_number' => 'SN-IRA',
            'customer_name' => 'IRA Test Customer',
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
            'title' => 'IRA team activity test case',
            'description' => 'IRA team activity test case.',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        return [$incident, $order];
    }
}
