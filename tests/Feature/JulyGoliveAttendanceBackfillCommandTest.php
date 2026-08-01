<?php

namespace Tests\Feature;

use App\Enums\AttendanceDayStatus;
use App\Enums\AttendanceMatrixCellKind;
use App\Enums\LeaveDuration;
use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use App\Services\Operations\AttendanceRegisterService;
use App\Support\Workforce\AttendanceMatrixCellMapper;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class JulyGoliveAttendanceBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_backfill_converts_not_started_jul_1_to_4_to_present(): void
    {
        $agent = $this->createScheduledAgent();
        $register = app(AttendanceRegisterService::class);

        foreach (['2026-07-01', '2026-07-02', '2026-07-03', '2026-07-04'] as $date) {
            $day = $register->refreshDay(
                $agent,
                Carbon::parse($date),
                Carbon::parse($date)->endOfDay(),
                allowPreShiftSkip: false,
            );

            $this->assertNotNull($day);
            $this->assertSame(AttendanceDayStatus::NotStarted, $day->status);
            $this->assertSame(
                AttendanceMatrixCellKind::Absent,
                app(AttendanceMatrixCellMapper::class)->kindFor($day, Carbon::parse($date), now()->startOfDay()),
            );
        }

        $this->artisan('workforce:july-golive-attendance-backfill', ['--force' => true])
            ->assertSuccessful();

        foreach (['2026-07-01', '2026-07-02', '2026-07-03', '2026-07-04'] as $date) {
            $day = WorkforceAttendanceDay::query()
                ->where('user_id', $agent->id)
                ->whereDate('work_date', $date)
                ->first();

            $this->assertNotNull($day);
            $this->assertSame(AttendanceDayStatus::Completed, $day->status);
            $this->assertTrue($day->on_time_login);
            $this->assertNotNull($day->finalized_at);
            $this->assertSame(0, (int) $day->session_count);
            $this->assertSame(
                AttendanceMatrixCellKind::Present,
                app(AttendanceMatrixCellMapper::class)->kindFor($day, Carbon::parse($date), now()->startOfDay()),
            );
        }
    }

    public function test_backfill_skips_approved_leave_days(): void
    {
        $agent = $this->createScheduledAgent();
        $register = app(AttendanceRegisterService::class);

        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-02',
            'reason' => 'Personal',
            'duration' => LeaveDuration::FullDay,
            'status' => LeaveRequestStatus::Approved,
        ]);

        foreach (['2026-07-01', '2026-07-02', '2026-07-03'] as $date) {
            $register->refreshDay(
                $agent,
                Carbon::parse($date),
                Carbon::parse($date)->endOfDay(),
                allowPreShiftSkip: false,
            );
        }

        $this->artisan('workforce:july-golive-attendance-backfill', ['--force' => true])
            ->assertSuccessful();

        $leaveDay = WorkforceAttendanceDay::query()
            ->where('user_id', $agent->id)
            ->whereDate('work_date', '2026-07-01')
            ->first();
        $this->assertNotNull($leaveDay);
        $this->assertSame(AttendanceDayStatus::OnLeave, $leaveDay->status);
        $this->assertSame(
            AttendanceMatrixCellKind::Leave,
            app(AttendanceMatrixCellMapper::class)->kindFor(
                $leaveDay,
                Carbon::parse('2026-07-01'),
                now()->startOfDay(),
            ),
        );

        $converted = WorkforceAttendanceDay::query()
            ->where('user_id', $agent->id)
            ->whereDate('work_date', '2026-07-03')
            ->first();
        $this->assertNotNull($converted);
        $this->assertSame(AttendanceDayStatus::Completed, $converted->status);
        $this->assertSame(
            AttendanceMatrixCellKind::Present,
            app(AttendanceMatrixCellMapper::class)->kindFor(
                $converted,
                Carbon::parse('2026-07-03'),
                now()->startOfDay(),
            ),
        );
    }

    public function test_refresh_day_does_not_overwrite_finalized_backfill(): void
    {
        $agent = $this->createScheduledAgent();
        $register = app(AttendanceRegisterService::class);
        $workDate = Carbon::parse('2026-07-01');

        $register->refreshDay(
            $agent,
            $workDate,
            $workDate->copy()->endOfDay(),
            allowPreShiftSkip: false,
        );

        $this->artisan('workforce:july-golive-attendance-backfill', ['--force' => true])
            ->assertSuccessful();

        $before = WorkforceAttendanceDay::query()
            ->where('user_id', $agent->id)
            ->whereDate('work_date', '2026-07-01')
            ->first();
        $this->assertNotNull($before);
        $this->assertSame(AttendanceDayStatus::Completed, $before->status);
        $this->assertNotNull($before->finalized_at);

        $after = $register->refreshDay(
            $agent,
            $workDate,
            $workDate->copy()->endOfDay(),
            allowPreShiftSkip: false,
        );

        $this->assertNotNull($after);
        $this->assertSame($before->id, $after->id);
        $this->assertSame(AttendanceDayStatus::Completed, $after->status);
        $this->assertTrue($after->on_time_login);
        $this->assertNotNull($after->finalized_at);
        $this->assertSame(
            AttendanceMatrixCellKind::Present,
            app(AttendanceMatrixCellMapper::class)->kindFor($after, $workDate, now()->startOfDay()),
        );
    }

    public function test_dry_run_does_not_write(): void
    {
        $agent = $this->createScheduledAgent();
        $register = app(AttendanceRegisterService::class);

        $register->refreshDay(
            $agent,
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-01')->endOfDay(),
            allowPreShiftSkip: false,
        );

        $this->artisan('workforce:july-golive-attendance-backfill', ['--dry-run' => true])
            ->assertSuccessful();

        $day = WorkforceAttendanceDay::query()
            ->where('user_id', $agent->id)
            ->whereDate('work_date', '2026-07-01')
            ->first();

        $this->assertNotNull($day);
        $this->assertSame(AttendanceDayStatus::NotStarted, $day->status);
        $this->assertFalse((bool) $day->on_time_login);
    }

    private function createScheduledAgent(): User
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $agent->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'weekly_off_days' => [Carbon::SUNDAY],
            'effective_from' => '2000-01-01',
        ]);

        return $agent->fresh(['workSchedule', 'roles']);
    }
}
