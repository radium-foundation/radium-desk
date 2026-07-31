<?php

namespace Tests\Feature;

use App\Enums\AttendanceDayStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use App\Models\WorkSession;
use App\Services\Workforce\MonthlyAttendanceMatrixService;
use App\Support\Workforce\AttendanceMatrixCellMapper;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScheduleBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-07-31 15:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_shipra_july_backfill_recalculates_session_and_matrix_ot(): void
    {
        $shipra = $this->createScheduledAgent(
            name: 'Shipra',
            start: '10:00:00',
            end: '18:30:00',
            weeklyOffDays: [Carbon::SUNDAY],
        );

        // After-hours session with stale OT=0 (day-shift schedule now applies).
        WorkSession::query()->create([
            'user_id' => $shipra->id,
            'work_date' => '2026-07-27',
            'login_at' => Carbon::parse('2026-07-27 19:00:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse('2026-07-27 22:00:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::AwayTimeout,
            'session_duration_seconds' => 3 * 3600,
            'active_duration_seconds' => 3 * 3600,
            'overtime_seconds' => 0,
            'expected_working_minutes' => 840,
            'on_time_login' => true,
            'is_attributable' => true,
            'last_activity_at' => Carbon::parse('2026-07-27 21:50:00', 'Asia/Kolkata'),
            'last_tick_at' => Carbon::parse('2026-07-27 22:00:00', 'Asia/Kolkata'),
        ]);

        WorkforceAttendanceDay::query()->create([
            'user_id' => $shipra->id,
            'work_date' => '2026-07-27',
            'status' => AttendanceDayStatus::Completed,
            'calendar_status' => 'working',
            'is_working_day' => true,
            'is_company_holiday' => false,
            'is_on_leave' => false,
            'has_schedule' => true,
            'session_count' => 1,
            'active_duration_seconds' => 3 * 3600,
            'overtime_seconds' => 0,
            'expected_working_minutes' => 840,
            'computed_at' => now()->subDay(),
            'source_version' => 1,
        ]);

        $this->artisan('workforce:schedule-backfill', [
            '--user' => $shipra->id,
            '--from' => '2026-07-27',
            '--to' => '2026-07-27',
        ])
            ->expectsOutputToContain('Processing User: Shipra')
            ->expectsOutputToContain('Sessions Updated:')
            ->assertSuccessful();

        $session = WorkSession::query()->where('user_id', $shipra->id)->first();
        $this->assertSame(3 * 3600, (int) $session->overtime_seconds);

        $day = WorkforceAttendanceDay::query()
            ->where('user_id', $shipra->id)
            ->whereDate('work_date', '2026-07-27')
            ->first();
        $this->assertSame(3 * 3600, (int) $day->overtime_seconds);

        $matrix = app(MonthlyAttendanceMatrixService::class)->buildForUser(
            $shipra,
            Carbon::parse('2026-07-01'),
        );
        $this->assertGreaterThan(0, $matrix->summary->overtimeSeconds);

        // Matrix/360 are read models — no separate monthly table to persist.
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasTable('workforce_attendance_monthly_summaries'),
        );
    }

    public function test_sunday_present_becomes_extra_for_weekly_off_agents(): void
    {
        foreach (['Jayram', 'Gaurav', 'Sushant', 'Sumit'] as $name) {
            $agent = $this->createScheduledAgent(
                name: $name,
                start: '09:00:00',
                end: '18:00:00',
                weeklyOffDays: [Carbon::SUNDAY],
            );
            $this->seedSundayPresentStaleRow($agent, '2026-07-26');

            $this->artisan('workforce:schedule-backfill', [
                '--user' => $agent->id,
                '--from' => '2026-07-26',
                '--to' => '2026-07-26',
            ])->assertSuccessful();

            $day = WorkforceAttendanceDay::query()
                ->where('user_id', $agent->id)
                ->whereDate('work_date', '2026-07-26')
                ->first();

            $this->assertSame(AttendanceDayStatus::Extra, $day->status, $name);
            $this->assertFalse((bool) $day->is_working_day, $name);

            $kind = app(AttendanceMatrixCellMapper::class)->kindFor(
                $day,
                Carbon::parse('2026-07-26'),
                now()->startOfDay(),
            );
            $this->assertSame('extra', $kind->value, $name);
        }
    }

    public function test_jyotsana_sunday_remains_present_when_monday_is_off(): void
    {
        $agent = $this->createScheduledAgent(
            name: 'Jyotsana',
            start: '18:00:00',
            end: '10:00:00',
            weeklyOffDays: [Carbon::MONDAY],
        );
        $this->seedSundayPresentStaleRow($agent, '2026-07-26', activeSeconds: 8 * 3600);

        $this->artisan('workforce:schedule-backfill', [
            '--user' => $agent->id,
            '--from' => '2026-07-26',
            '--to' => '2026-07-26',
        ])->assertSuccessful();

        $day = WorkforceAttendanceDay::query()
            ->where('user_id', $agent->id)
            ->whereDate('work_date', '2026-07-26')
            ->first();

        $this->assertSame(AttendanceDayStatus::Completed, $day->status);
        $this->assertTrue((bool) $day->is_working_day);

        $kind = app(AttendanceMatrixCellMapper::class)->kindFor(
            $day,
            Carbon::parse('2026-07-26'),
            now()->startOfDay(),
        );
        $this->assertSame('present', $kind->value);
    }

    public function test_dry_run_does_not_write_but_reports_projected_ot(): void
    {
        $agent = $this->createScheduledAgent(
            name: 'Shipra',
            start: '10:00:00',
            end: '18:30:00',
            weeklyOffDays: [Carbon::SUNDAY],
        );

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-11',
            'login_at' => Carbon::parse('2026-07-11 19:00:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse('2026-07-11 20:00:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::AwayTimeout,
            'session_duration_seconds' => 3600,
            'active_duration_seconds' => 3600,
            'overtime_seconds' => 0,
            'is_attributable' => true,
            'last_activity_at' => Carbon::parse('2026-07-11 19:50:00', 'Asia/Kolkata'),
            'last_tick_at' => Carbon::parse('2026-07-11 20:00:00', 'Asia/Kolkata'),
        ]);

        WorkforceAttendanceDay::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-11',
            'status' => AttendanceDayStatus::Completed,
            'calendar_status' => 'working',
            'is_working_day' => true,
            'is_company_holiday' => false,
            'is_on_leave' => false,
            'has_schedule' => true,
            'session_count' => 1,
            'active_duration_seconds' => 3600,
            'overtime_seconds' => 0,
            'computed_at' => now()->subDay(),
            'source_version' => 1,
        ]);

        $this->artisan('workforce:schedule-backfill', [
            '--user' => $agent->id,
            '--from' => '2026-07-11',
            '--to' => '2026-07-11',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('OT: 0 -> 3600')
            ->assertSuccessful();

        $this->assertSame(0, (int) WorkSession::query()->where('user_id', $agent->id)->value('overtime_seconds'));
        $this->assertSame(0, (int) WorkforceAttendanceDay::query()->where('user_id', $agent->id)->value('overtime_seconds'));
    }

    /**
     * @param  list<int>  $weeklyOffDays
     */
    private function createScheduledAgent(
        string $name,
        string $start,
        string $end,
        array $weeklyOffDays,
    ): User {
        $user = User::factory()->create([
            'name' => $name,
            'first_name' => $name,
            'last_name' => '',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => $start,
            'work_end_time' => $end,
            'lunch_start_time' => '13:00:00',
            'lunch_end_time' => '13:30:00',
            'short_break_count' => 0,
            'short_break_minutes' => 10,
            'weekly_off_days' => $weeklyOffDays,
            'effective_from' => '2000-01-01',
        ]);

        return $user->fresh(['workSchedule', 'roles']);
    }

    private function seedSundayPresentStaleRow(
        User $agent,
        string $date,
        int $activeSeconds = 3600,
    ): void {
        $loginAt = Carbon::parse("{$date} 10:00:00", 'Asia/Kolkata');
        $logoutAt = Carbon::parse("{$date} 11:00:00", 'Asia/Kolkata');

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => $date,
            'login_at' => $loginAt,
            'logout_at' => $logoutAt,
            'ended_reason' => WorkSessionEndReason::AwayTimeout,
            'session_duration_seconds' => (int) $loginAt->diffInSeconds($logoutAt),
            'active_duration_seconds' => $activeSeconds,
            'overtime_seconds' => 0,
            'cases_handled_count' => 1,
            'is_attributable' => true,
            'origin' => 'migration',
            'last_activity_at' => $logoutAt,
            'last_tick_at' => $logoutAt,
        ]);

        WorkforceAttendanceDay::query()->create([
            'user_id' => $agent->id,
            'work_date' => $date,
            'status' => AttendanceDayStatus::Completed,
            'calendar_status' => 'working',
            'is_working_day' => true,
            'is_company_holiday' => false,
            'is_on_leave' => false,
            'has_schedule' => true,
            'session_count' => 1,
            'active_duration_seconds' => $activeSeconds,
            'overtime_seconds' => 0,
            'first_login_at' => $loginAt,
            'last_logout_at' => $logoutAt,
            'computed_at' => Carbon::parse('2026-07-28 11:52:58', 'Asia/Kolkata'),
            'source_version' => 1,
        ]);
    }
}
