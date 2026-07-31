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
            '--force' => true,
        ])
            ->expectsOutputToContain('[1/1] Shipra')
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
                '--force' => true,
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
            '--force' => true,
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
            '--force' => true,
        ])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('OT: 0 -> 3600')
            ->assertSuccessful();

        $this->assertSame(0, (int) WorkSession::query()->where('user_id', $agent->id)->value('overtime_seconds'));
        $this->assertSame(0, (int) WorkforceAttendanceDay::query()->where('user_id', $agent->id)->value('overtime_seconds'));
    }

    public function test_all_force_backfills_affected_and_skips_unaffected(): void
    {
        $shipra = $this->createScheduledAgent('Shipra', '10:00:00', '18:30:00', [Carbon::SUNDAY]);
        TeamMemberWorkSchedule::query()->where('user_id', $shipra->id)->update([
            'updated_at' => Carbon::parse('2026-07-31 12:20:00', 'Asia/Kolkata'),
        ]);
        WorkSession::query()->create([
            'user_id' => $shipra->id,
            'work_date' => '2026-07-27',
            'login_at' => Carbon::parse('2026-07-27 19:00:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse('2026-07-27 22:00:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::AwayTimeout,
            'session_duration_seconds' => 3 * 3600,
            'active_duration_seconds' => 3 * 3600,
            'overtime_seconds' => 0,
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
            'computed_at' => now()->subDay(),
            'source_version' => 1,
        ]);

        $gaurav = $this->createScheduledAgent('Gaurav', '09:00:00', '18:00:00', [Carbon::SUNDAY]);
        TeamMemberWorkSchedule::query()->where('user_id', $gaurav->id)->update([
            'updated_at' => Carbon::parse('2026-07-31 12:05:00', 'Asia/Kolkata'),
        ]);
        $this->seedSundayPresentStaleRow($gaurav, '2026-07-26');

        $jyotsana = $this->createScheduledAgent('Jyotsana', '18:00:00', '10:00:00', [Carbon::MONDAY]);
        TeamMemberWorkSchedule::query()->where('user_id', $jyotsana->id)->update([
            'updated_at' => Carbon::parse('2026-07-31 12:08:00', 'Asia/Kolkata'),
        ]);
        $this->seedSundayPresentStaleRow($jyotsana, '2026-07-26', activeSeconds: 8 * 3600);

        // Unaffected: correct Extra already, no OT issue, schedule unchanged long ago.
        $stable = $this->createScheduledAgent('Stable Agent', '09:00:00', '18:00:00', [Carbon::SUNDAY]);
        TeamMemberWorkSchedule::query()->where('user_id', $stable->id)->update([
            'created_at' => Carbon::parse('2026-07-01 10:00:00', 'Asia/Kolkata'),
            'updated_at' => Carbon::parse('2026-07-01 10:00:00', 'Asia/Kolkata'),
        ]);
        WorkSession::query()->create([
            'user_id' => $stable->id,
            'work_date' => '2026-07-26',
            'login_at' => Carbon::parse('2026-07-26 10:00:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse('2026-07-26 11:00:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::AwayTimeout,
            'session_duration_seconds' => 3600,
            'active_duration_seconds' => 3600,
            'overtime_seconds' => 0,
            'is_attributable' => true,
            'last_activity_at' => Carbon::parse('2026-07-26 11:00:00', 'Asia/Kolkata'),
            'last_tick_at' => Carbon::parse('2026-07-26 11:00:00', 'Asia/Kolkata'),
        ]);
        WorkforceAttendanceDay::query()->create([
            'user_id' => $stable->id,
            'work_date' => '2026-07-26',
            'status' => AttendanceDayStatus::Extra,
            'calendar_status' => 'weekly_off',
            'is_working_day' => false,
            'is_company_holiday' => false,
            'is_on_leave' => false,
            'has_schedule' => true,
            'session_count' => 1,
            'active_duration_seconds' => 3600,
            'overtime_seconds' => 0,
            'computed_at' => now()->subDay(),
            'source_version' => 1,
        ]);
        $stableDayBefore = WorkforceAttendanceDay::query()->where('user_id', $stable->id)->first()->fresh();

        $this->artisan('workforce:schedule-backfill', [
            '--all' => true,
            '--from' => '2026-07-26',
            '--to' => '2026-07-27',
            '--force' => true,
        ])
            ->expectsOutputToContain('Employees Selected: 4')
            ->expectsOutputToContain('Present → Extra:')
            ->assertSuccessful();

        $this->assertSame(3 * 3600, (int) WorkSession::query()->where('user_id', $shipra->id)->value('overtime_seconds'));
        $this->assertSame(
            AttendanceDayStatus::Extra,
            WorkforceAttendanceDay::query()->where('user_id', $gaurav->id)->whereDate('work_date', '2026-07-26')->first()->status,
        );
        $this->assertSame(
            AttendanceDayStatus::Completed,
            WorkforceAttendanceDay::query()->where('user_id', $jyotsana->id)->whereDate('work_date', '2026-07-26')->first()->status,
        );

        $stableDayAfter = WorkforceAttendanceDay::query()->where('user_id', $stable->id)->first()->fresh();
        $this->assertSame($stableDayBefore->status, $stableDayAfter->status);
        $this->assertSame((int) $stableDayBefore->overtime_seconds, (int) $stableDayAfter->overtime_seconds);
        $this->assertSame(0, (int) WorkSession::query()->where('user_id', $stable->id)->value('overtime_seconds'));
    }

    public function test_changed_since_filters_schedule_edits_and_all_dry_run_writes_nothing(): void
    {
        $recent = $this->createScheduledAgent('Recent', '10:00:00', '18:30:00', [Carbon::SUNDAY]);
        TeamMemberWorkSchedule::query()->where('user_id', $recent->id)->update([
            'updated_at' => Carbon::parse('2026-07-31 12:00:00', 'Asia/Kolkata'),
        ]);
        WorkSession::query()->create([
            'user_id' => $recent->id,
            'work_date' => '2026-07-27',
            'login_at' => Carbon::parse('2026-07-27 19:00:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse('2026-07-27 20:00:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::AwayTimeout,
            'session_duration_seconds' => 3600,
            'active_duration_seconds' => 3600,
            'overtime_seconds' => 0,
            'is_attributable' => true,
            'last_activity_at' => Carbon::parse('2026-07-27 19:50:00', 'Asia/Kolkata'),
            'last_tick_at' => Carbon::parse('2026-07-27 20:00:00', 'Asia/Kolkata'),
        ]);
        WorkforceAttendanceDay::query()->create([
            'user_id' => $recent->id,
            'work_date' => '2026-07-27',
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

        $old = $this->createScheduledAgent('Old Schedule', '09:00:00', '18:00:00', [Carbon::SUNDAY]);
        TeamMemberWorkSchedule::query()->where('user_id', $old->id)->update([
            'created_at' => Carbon::parse('2026-07-01 10:00:00', 'Asia/Kolkata'),
            'updated_at' => Carbon::parse('2026-07-01 10:00:00', 'Asia/Kolkata'),
        ]);

        $this->artisan('workforce:schedule-backfill', [
            '--all' => true,
            '--changed-since' => '2026-07-31',
            '--from' => '2026-07-27',
            '--to' => '2026-07-27',
            '--dry-run' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('Employees Selected: 1')
            ->expectsOutputToContain('Recent')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Total OT Difference:')
            ->assertSuccessful();

        $this->assertSame(0, (int) WorkSession::query()->where('user_id', $recent->id)->value('overtime_seconds'));
        $this->assertSame(0, (int) WorkforceAttendanceDay::query()->where('user_id', $recent->id)->value('overtime_seconds'));
    }

    public function test_aborts_without_force_when_confirmation_declined(): void
    {
        $agent = $this->createScheduledAgent('Shipra', '10:00:00', '18:30:00', [Carbon::SUNDAY]);

        $this->artisan('workforce:schedule-backfill', [
            '--user' => $agent->id,
            '--from' => '2026-07-27',
            '--to' => '2026-07-27',
        ])
            ->expectsConfirmation('Proceed with backfill?', 'no')
            ->expectsOutputToContain('Aborted')
            ->assertSuccessful();
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
