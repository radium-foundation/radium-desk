<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\LeaveRequestStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\LeaveRequest;
use App\Models\Order;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Dashboard\TeamActivityPanelService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\PresenceEngineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTeamActivityUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['dashboard-team-activity.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-07-26 11:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_premium_layout_includes_column_headers_and_avatar(): void
    {
        $viewer = $this->supervisor();
        $agent = $this->createAgent('Shipra Patel', startSession: true);
        $this->createAssignmentAudit($agent);

        $html = $this->panelHtml($viewer);

        $this->assertStringContainsString('Team Member', $html);
        $this->assertStringContainsString('Latest Event', $html);
        $this->assertStringContainsString('Pending', $html);
        $this->assertStringContainsString('team-activity-avatar__inner', $html);
        $this->assertStringContainsString('SP', $html);
        $this->assertStringContainsString('>Shipra<', $html);
        $this->assertStringContainsString('title="Shipra Patel"', $html);
        $this->assertStringContainsString('team-activity-status-pill--working', $html);
        $this->assertStringContainsString('team-activity-live-presence', $html);
    }

    public function test_zero_activity_count_renders_emphasized_kpi(): void
    {
        $viewer = $this->supervisor();
        $this->createAgent('Zero Activity Agent');

        $html = $this->panelHtml($viewer);

        $this->assertStringContainsString('team-activity-kpi-count">0<', $html);
        $this->assertStringContainsString('Outcome · Effort', $html);
        $this->assertStringContainsString('aria-label="0 Cases Worked; 0 Customer Touches"', $html);
    }

    public function test_high_activity_count_and_latest_event_formatting(): void
    {
        $viewer = $this->supervisor();
        $agent = $this->createAgent('High Volume Agent', startSession: true);
        [$incident, $order] = $this->createIncident($agent, 'RD3462168');

        foreach (range(1, 12) as $index) {
            AuditLog::query()->create([
                'user_id' => $agent->id,
                'event' => 'service_case.status_changed',
                'auditable_type' => $incident->getMorphClass(),
                'auditable_id' => $incident->id,
                'created_at' => now()->subMinutes($index),
            ]);
        }

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'service_reference.driver_guide_sent',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => now()->subHour(),
        ]);

        $html = $this->panelHtml($viewer);

        $this->assertStringContainsString('team-activity-kpi-count">1<', $html);
        $this->assertStringContainsString('team-activity-latest-event__title', $html);
        $this->assertStringContainsString('bi-file-earmark-text', $html);
        $this->assertStringContainsString('Guide Sent', $html);
        $this->assertStringNotContainsString('Driver Guide Sent', $html);
        $this->assertStringContainsString($incident->reference_no, $html);
        $this->assertStringNotContainsString('RD3462168', $html);
        $this->assertStringNotContainsString('team-activity-latest-event__time', $html);
    }

    public function test_presence_shows_compact_latest_and_previous_elapsed_times(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 10:30:00', 'Asia/Kolkata'));

        $viewer = $this->supervisor();
        $agent = $this->createAgent('Presence Agent');
        [$incident] = $this->createIncident($agent);

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => now()->toDateString(),
            'login_at' => now()->subHours(2),
            'session_duration_seconds' => 7200,
        ]);

        $older = AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'service_case.status_changed',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);
        $older->created_at = now()->subMinutes(25);
        $older->saveQuietly();

        $latest = AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'service_case.assigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);
        $latest->created_at = now()->subMinutes(5);
        $latest->saveQuietly();

        $row = collect(app(TeamActivityPanelService::class)->build()->agents)
            ->firstWhere('id', $agent->id);

        $this->assertNotNull($row);
        $this->assertNotNull($row->previousActivityAt);
        $this->assertSame('5m', display_team_activity_elapsed($row->latestActivityAt));
        $this->assertSame('25m', display_team_activity_elapsed($row->previousActivityAt));

        $html = $this->panelHtml($viewer);

        $this->assertStringContainsString('Previous', $html);
        $this->assertStringContainsString('team-activity-duration__value">5<', $html);
        $this->assertStringContainsString('team-activity-duration__unit">m<', $html);
        $this->assertStringContainsString('team-activity-duration__value">25<', $html);
        $this->assertStringNotContainsString(' ago', $html);
    }

    public function test_weekly_off_renders_outlined_calendar_badge(): void
    {
        $viewer = $this->supervisor();
        $agent = $this->createAgent('Weekly Off Agent', startSession: true);

        TeamMemberWorkSchedule::query()
            ->where('user_id', $agent->id)
            ->update(['weekly_off_days' => [now()->dayOfWeek]]);

        $html = $this->panelHtml($viewer);

        $this->assertStringContainsString('team-activity-calendar-pill', $html);
        $this->assertStringContainsString('Weekly Off', $html);
        $this->assertStringContainsString('team-activity-status-pill--working', $html);
        $this->assertStringContainsString('team-activity-live-presence', $html);
    }

    public function test_ira_virtual_row_uses_ira_avatar_and_status_ring(): void
    {
        $viewer = $this->supervisor();
        $this->createAgent('Human Agent', startSession: true);

        $html = $this->panelHtml($viewer);

        $this->assertStringContainsString('is-virtual', $html);
        $this->assertStringContainsString('team-activity-avatar--ira', $html);
        $this->assertStringContainsString('team-activity-avatar--virtual', $html);
        $this->assertStringContainsString('team-activity-status-pill--ira', $html);
        $this->assertStringContainsString('team-activity-kpi--ira', $html);
        $this->assertStringContainsString('team-activity-kpi-supplementary', $html);
        $this->assertStringNotContainsString('calendar-pill__icon', $html);
    }

    public function test_auto_logged_out_and_offline_member_status_render(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-26 08:00:00', 'Asia/Kolkata'));

        $viewer = $this->supervisor();
        $autoLogout = $this->createAgent('Auto Logout Agent');
        $offline = $this->createAgent('Offline Agent');

        WorkSession::query()->create([
            'user_id' => $autoLogout->id,
            'work_date' => now()->toDateString(),
            'login_at' => now()->subHours(2),
            'logout_at' => now()->subHour(),
            'ended_reason' => WorkSessionEndReason::AwayTimeout,
            'session_duration_seconds' => 3600,
        ]);

        $html = $this->panelHtml($viewer);

        $this->assertStringContainsString('team-activity-status-pill--auto_logout', $html);
        $this->assertStringContainsString('Auto Logged Out', $html);
        $this->assertStringContainsString('team-activity-status-pill--offline', $html);

        Carbon::setTestNow(Carbon::parse('2026-07-26 11:00:00', 'Asia/Kolkata'));
    }

    public function test_leave_status_and_long_name_truncation_classes_present(): void
    {
        $viewer = $this->supervisor();
        $agent = $this->createAgent('Very Long Employee Name That Should Ellipsize In Compact Layout');
        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'reason' => 'Annual Leave',
            'status' => LeaveRequestStatus::Approved,
        ]);

        $html = $this->panelHtml($viewer);

        $this->assertStringContainsString('team-activity-status-pill--leave', $html);
        $this->assertStringContainsString('On Leave', $html);
        $this->assertStringContainsString('team-activity-name', $html);
        $this->assertStringContainsString('Very Long Employee Name', $html);
        $this->assertStringContainsString('Annual Leave', $html);
        $this->assertStringNotContainsString('team-activity-operational-indicator--late', $html);
    }

    public function test_late_employee_renders_secondary_indicator_in_presence_column(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 10:30:00', 'Asia/Kolkata'));

        $viewer = $this->supervisor();
        $late = $this->createAgent('Jayram Late');
        $onTime = $this->createAgent('On Time Agent');

        WorkSession::query()->create([
            'user_id' => $late->id,
            'work_date' => now()->toDateString(),
            'login_at' => Carbon::parse('2026-07-28 09:33:00', 'Asia/Kolkata'),
            'on_time_login' => false,
            'session_duration_seconds' => 3420,
        ]);
        WorkSession::query()->create([
            'user_id' => $onTime->id,
            'work_date' => now()->toDateString(),
            'login_at' => Carbon::parse('2026-07-28 09:00:00', 'Asia/Kolkata'),
            'on_time_login' => true,
            'session_duration_seconds' => 5400,
        ]);

        app(\App\Services\Operations\AttendanceRegisterService::class)
            ->refreshDay($late, now()->startOfDay(), now());
        app(\App\Services\Operations\AttendanceRegisterService::class)
            ->refreshDay($onTime, now()->startOfDay(), now());

        $panel = app(TeamActivityPanelService::class)->build();
        $lateRow = collect($panel->agents)->firstWhere('id', $late->id);
        $onTimeRow = collect($panel->agents)->firstWhere('id', $onTime->id);

        $this->assertNotNull($lateRow);
        $this->assertSame(33, $lateRow->minutesLate);
        $this->assertNotNull($onTimeRow);
        $this->assertNull($onTimeRow->minutesLate);

        $html = $this->panelHtml($viewer);
        $lateHtml = $this->agentRowHtml($html, $late->id);

        $this->assertStringContainsString('team-activity-live-presence', $lateHtml);
        $this->assertStringContainsString('team-activity-operational-indicator--late', $lateHtml);
        $this->assertStringContainsString('team-activity-operational-indicator__late-mark">L<', $lateHtml);
        $this->assertStringContainsString('title="33m late"', $lateHtml);
        $this->assertStringContainsString('aria-label="Active · L33m"', $lateHtml);
        $this->assertStringContainsString('team-activity-status-pill--working', $lateHtml);
        $this->assertStringContainsString('team-activity-presence-metrics', $lateHtml);
        $this->assertStringNotContainsString('team-activity-member-status', $lateHtml);

        $onTimeHtmlSlice = $this->agentRowHtml($html, $onTime->id);
        $this->assertStringNotContainsString('team-activity-operational-indicator--late', $onTimeHtmlSlice);
        $this->assertStringNotContainsString('L33m', $onTimeHtmlSlice);
    }

    public function test_leave_and_weekly_off_do_not_render_late_indicator(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 10:30:00', 'Asia/Kolkata'));

        $viewer = $this->supervisor();
        $onLeave = $this->createAgent('Leave Agent');
        LeaveRequest::query()->create([
            'user_id' => $onLeave->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'reason' => 'Annual Leave',
            'status' => LeaveRequestStatus::Approved,
        ]);

        $weeklyOff = $this->createAgent('Weekly Off Agent');
        TeamMemberWorkSchedule::query()
            ->where('user_id', $weeklyOff->id)
            ->update(['weekly_off_days' => [now()->dayOfWeek]]);

        app(\App\Services\Operations\AttendanceRegisterService::class)
            ->refreshDay($onLeave, now()->startOfDay(), now());
        app(\App\Services\Operations\AttendanceRegisterService::class)
            ->refreshDay($weeklyOff, now()->startOfDay(), now());

        $html = $this->panelHtml($viewer);

        $leaveHtml = $this->agentRowHtml($html, $onLeave->id);
        $weeklyHtml = $this->agentRowHtml($html, $weeklyOff->id);

        $this->assertStringContainsString('team-activity-status-pill--leave', $leaveHtml);
        $this->assertStringContainsString('Annual Leave', $leaveHtml);
        $this->assertStringNotContainsString('team-activity-operational-indicator--late', $leaveHtml);

        $this->assertStringContainsString('Weekly Off', $weeklyHtml);
        $this->assertStringNotContainsString('team-activity-operational-indicator--late', $weeklyHtml);
    }

    public function test_holiday_renders_calendar_badge_without_late_indicator(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 10:30:00', 'Asia/Kolkata'));

        $viewer = $this->supervisor();
        $agent = $this->createAgent('Holiday Agent');

        \App\Models\CompanyHoliday::query()->create([
            'holiday_date' => now()->toDateString(),
            'name' => 'Independence Day',
            'type' => \App\Enums\CompanyHolidayType::Company,
        ]);

        app(\App\Services\Operations\AttendanceRegisterService::class)
            ->refreshDay($agent, now()->startOfDay(), now());

        $html = $this->panelHtml($viewer);
        $rowHtml = $this->agentRowHtml($html, $agent->id);

        $this->assertStringContainsString('team-activity-calendar-pill', $rowHtml);
        $this->assertStringContainsString('Holiday', $rowHtml);
        $this->assertStringNotContainsString('team-activity-operational-indicator--late', $rowHtml);
    }

    public function test_presence_column_layout_classes_are_present(): void
    {
        $viewer = $this->supervisor();
        $this->createAgent('Layout Agent', startSession: true);

        $html = $this->panelHtml($viewer);

        $this->assertStringContainsString('team-activity-presence-layout', $html);
        $this->assertStringContainsString('team-activity-presence-metrics', $html);
        $this->assertStringContainsString('team-activity-presence-header', $html);
        $this->assertStringContainsString('team-activity-presence-legend-trigger', $html);
        $this->assertStringContainsString('aria-label="Presence status legend"', $html);
        $this->assertStringContainsString('Presence legend', $html);
        $this->assertStringContainsString('team-activity-presence-legend__abbr">A<', $html);
        $this->assertStringContainsString('team-activity-presence-legend__abbr">L<', $html);
        $this->assertStringContainsString('team-activity-presence-legend__abbr">WFH<', $html);
        $this->assertStringContainsString('(future)', $html);
        $this->assertStringContainsString('bi-info-circle', $html);
    }

    private function agentRowHtml(string $panelHtml, int $agentId): string
    {
        if (! preg_match(
            '/data-team-activity-agent="'.$agentId.'"[^>]*>.*?(?=<li class="team-activity-row|<\/ul>)/s',
            $panelHtml,
            $matches,
        )) {
            $this->fail("Could not isolate Team Activity row for agent {$agentId}");
        }

        return $matches[0];
    }

    private function panelHtml(User $viewer): string
    {
        return (string) $this->actingAs($viewer)
            ->getJson(route('dashboard.team-activity'))
            ->assertOk()
            ->json('html');
    }

    private function supervisor(): User
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $viewer;
    }

    private function createAgent(string $name, bool $startSession = false): User
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

    private function createAssignmentAudit(User $agent): void
    {
        [$incident] = $this->createIncident($agent);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'service_case.assigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array{0: Incident, 1: Order}
     */
    private function createIncident(User $user, string $orderId = 'RD1000999'): array
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-UI',
            'customer_name' => 'UI Test Customer',
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
            'title' => 'UI test case',
            'description' => 'UI test case.',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        return [$incident, $order];
    }
}
