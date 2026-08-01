<?php

namespace Tests\Feature;

use App\Enums\AttendanceDayStatus;
use App\Enums\AttendanceMatrixCellKind;
use App\Enums\CompanyHolidayType;
use App\Enums\LeaveDuration;
use App\Enums\LeaveRequestStatus;
use App\Enums\WorkCalendarDayStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\CompanyHoliday;
use App\Models\LeaveRequest;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkSession;
use App\Models\WorkforceAttendanceDay;
use App\Services\Operations\JulyAttendancePayrollRepairService;
use App\Support\Workforce\AttendanceMatrixCellMapper;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class JulyAttendancePayrollRepairCommandTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceMatrixCellMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->mapper = app(AttendanceMatrixCellMapper::class);
        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_riya_leave_sundays_off_else_present(): void
    {
        $riya = $this->makeEmployee('Riya');
        $this->approveLeave($riya, '2026-07-03', LeaveDuration::FullDay);

        // Corrupt mid-month Absent rows (production pattern).
        $this->seedNotStarted($riya, '2026-07-06');
        $this->seedNotStarted($riya, '2026-07-12'); // Sunday

        $this->artisan('workforce:july-attendance-payroll-repair', ['--force' => true])
            ->assertSuccessful();

        $this->assertKind($riya, '2026-07-03', AttendanceMatrixCellKind::Leave);
        $this->assertKind($riya, '2026-07-05', AttendanceMatrixCellKind::WeeklyOff);
        $this->assertKind($riya, '2026-07-12', AttendanceMatrixCellKind::WeeklyOff);
        $this->assertKind($riya, '2026-07-19', AttendanceMatrixCellKind::WeeklyOff);
        $this->assertKind($riya, '2026-07-26', AttendanceMatrixCellKind::WeeklyOff);
        $this->assertKind($riya, '2026-07-01', AttendanceMatrixCellKind::Present);
        $this->assertKind($riya, '2026-07-06', AttendanceMatrixCellKind::Present);
        $this->assertKind($riya, '2026-07-31', AttendanceMatrixCellKind::Present);
        $this->assertSame(0, WorkSession::query()->where('user_id', $riya->id)->count());
    }

    public function test_shashank_leave_and_weekly_off_and_present(): void
    {
        $shashank = $this->makeEmployee('Shashank');

        foreach (['2026-07-02', '2026-07-19', '2026-07-20', '2026-07-24', '2026-07-28', '2026-07-30'] as $date) {
            $this->approveLeave($shashank, $date, LeaveDuration::FullDay);
        }

        $this->artisan('workforce:july-attendance-payroll-repair', ['--force' => true])
            ->assertSuccessful();

        foreach (['2026-07-02', '2026-07-19', '2026-07-20', '2026-07-24', '2026-07-28', '2026-07-30'] as $date) {
            $this->assertKind($shashank, $date, AttendanceMatrixCellKind::Leave);
        }

        // Sundays without leave → Weekly Off (Jul 19 is leave).
        $this->assertKind($shashank, '2026-07-05', AttendanceMatrixCellKind::WeeklyOff);
        $this->assertKind($shashank, '2026-07-12', AttendanceMatrixCellKind::WeeklyOff);
        $this->assertKind($shashank, '2026-07-26', AttendanceMatrixCellKind::WeeklyOff);
        $this->assertKind($shashank, '2026-07-06', AttendanceMatrixCellKind::Present);
        $this->assertKind($shashank, '2026-07-21', AttendanceMatrixCellKind::Present);
    }

    public function test_gaurav_sunday_worked_extra_sunday_idle_weekly_off(): void
    {
        $gaurav = $this->makeAgent('Gaurav');
        // Late schedule (production pattern) — Jul 26 not covered by scheduleFor.
        TeamMemberWorkSchedule::query()->create([
            'user_id' => $gaurav->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'weekly_off_days' => [Carbon::SUNDAY],
            'effective_from' => '2026-07-31',
        ]);

        $this->seedClosedSession($gaurav, '2026-07-12');
        $this->seedClosedSession($gaurav, '2026-07-26');
        // Wrong Present on Sunday from null-schedule calculator path.
        $this->seedCompletedPresent($gaurav, '2026-07-12');
        $this->seedCompletedPresent($gaurav, '2026-07-26');
        $this->seedNotStarted($gaurav, '2026-07-19'); // Sunday, no work

        $this->artisan('workforce:july-attendance-payroll-repair', ['--force' => true])
            ->assertSuccessful();

        $this->assertKind($gaurav, '2026-07-12', AttendanceMatrixCellKind::Extra);
        $this->assertKind($gaurav, '2026-07-26', AttendanceMatrixCellKind::Extra);
        $this->assertKind($gaurav, '2026-07-19', AttendanceMatrixCellKind::WeeklyOff);
        $this->assertNotSame(
            AttendanceMatrixCellKind::Present,
            $this->kind($gaurav, '2026-07-12'),
        );
        $this->assertKind($gaurav, '2026-07-06', AttendanceMatrixCellKind::Present);
    }

    public function test_never_downgrades_extra_to_weekly_off(): void
    {
        $agent = $this->makeAgent('Extra Keep');
        $this->seedExtra($agent, '2026-07-12'); // Sunday Extra, no sessions

        $this->artisan('workforce:july-attendance-payroll-repair', ['--force' => true])
            ->assertSuccessful();

        $this->assertKind($agent, '2026-07-12', AttendanceMatrixCellKind::Extra);
        $day = $this->day($agent, '2026-07-12');
        $this->assertSame(AttendanceDayStatus::Extra, $day->status);
    }

    public function test_holiday_without_work_and_holiday_with_work(): void
    {
        $agent = $this->makeAgent('Holiday Agent');
        CompanyHoliday::query()->create([
            'holiday_date' => '2026-07-08',
            'name' => 'Company Event',
            'type' => CompanyHolidayType::Company,
        ]);

        $this->seedClosedSession($agent, '2026-07-08');
        $this->seedNotStarted($agent, '2026-07-15'); // will become Present
        CompanyHoliday::query()->create([
            'holiday_date' => '2026-07-22',
            'name' => 'Quiet Day',
            'type' => CompanyHolidayType::Company,
        ]);

        $this->artisan('workforce:july-attendance-payroll-repair', ['--force' => true])
            ->assertSuccessful();

        $this->assertKind($agent, '2026-07-08', AttendanceMatrixCellKind::Extra);
        $this->assertKind($agent, '2026-07-22', AttendanceMatrixCellKind::Holiday);
        $this->assertKind($agent, '2026-07-15', AttendanceMatrixCellKind::Present);
    }

    public function test_half_day_preserved(): void
    {
        $agent = $this->makeAgent('Half Day Agent');
        $this->approveLeave($agent, '2026-07-15', LeaveDuration::HalfDay);

        $this->artisan('workforce:july-attendance-payroll-repair', ['--force' => true])
            ->assertSuccessful();

        $this->assertKind($agent, '2026-07-15', AttendanceMatrixCellKind::HalfDay);
    }

    public function test_idempotent_second_run_unchanged(): void
    {
        $riya = $this->makeEmployee('Riya Idem');
        $this->approveLeave($riya, '2026-07-03', LeaveDuration::FullDay);

        $this->artisan('workforce:july-attendance-payroll-repair', ['--force' => true])
            ->assertSuccessful();

        $first = $this->day($riya, '2026-07-06');
        $this->assertNotNull($first);

        $this->artisan('workforce:july-attendance-payroll-repair', ['--force' => true])
            ->expectsOutputToContain('changed: 0')
            ->assertSuccessful();

        $second = $this->day($riya, '2026-07-06');
        $this->assertSame($first->updated_at?->toDateTimeString(), $second?->updated_at?->toDateTimeString());
        $this->assertKind($riya, '2026-07-06', AttendanceMatrixCellKind::Present);
    }

    public function test_dry_run_does_not_write(): void
    {
        $riya = $this->makeEmployee('Riya Dry');
        $this->seedNotStarted($riya, '2026-07-06');

        $this->artisan('workforce:july-attendance-payroll-repair', ['--dry-run' => true])
            ->assertSuccessful();

        $day = $this->day($riya, '2026-07-06');
        $this->assertNotNull($day);
        $this->assertSame(AttendanceDayStatus::NotStarted, $day->status);
        $this->assertSame(0, WorkforceAttendanceDay::query()
            ->where('user_id', $riya->id)
            ->where('status', AttendanceDayStatus::Completed)
            ->count());
    }

    public function test_does_not_touch_august(): void
    {
        $riya = $this->makeEmployee('Riya Aug');
        $this->seedNotStarted($riya, '2026-08-03');

        $this->artisan('workforce:july-attendance-payroll-repair', ['--force' => true])
            ->assertSuccessful();

        $aug = $this->day($riya, '2026-08-03');
        $this->assertNotNull($aug);
        $this->assertSame(AttendanceDayStatus::NotStarted, $aug->status);
    }

    public function test_service_weekly_off_uses_default_sunday_without_schedule(): void
    {
        $user = $this->makeEmployee('No Schedule');
        $service = app(JulyAttendancePayrollRepairService::class);

        $this->assertTrue($service->isWeeklyOffForJulyRepair($user, Carbon::parse('2026-07-12')));
        $this->assertFalse($service->isWeeklyOffForJulyRepair($user, Carbon::parse('2026-07-13')));
    }

    private function makeEmployee(string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_EMPLOYEE);

        return $user->fresh(['roles']);
    }

    private function makeAgent(string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user->fresh(['roles']);
    }

    private function approveLeave(User $user, string $date, LeaveDuration $duration): void
    {
        LeaveRequest::query()->create([
            'user_id' => $user->id,
            'start_date' => $date,
            'end_date' => $date,
            'reason' => 'July test leave',
            'duration' => $duration,
            'status' => LeaveRequestStatus::Approved,
        ]);
    }

    private function seedNotStarted(User $user, string $date): void
    {
        WorkforceAttendanceDay::query()->create([
            'user_id' => $user->id,
            'work_date' => $date,
            'status' => AttendanceDayStatus::NotStarted,
            'calendar_status' => WorkCalendarDayStatus::Working,
            'is_working_day' => true,
            'is_company_holiday' => false,
            'is_on_leave' => false,
            'has_schedule' => false,
            'session_count' => 0,
            'on_time_login' => null,
            'finalized_at' => Carbon::parse($date)->endOfDay(),
            'computed_at' => Carbon::parse($date)->endOfDay(),
            'source_version' => 1,
        ]);
    }

    private function seedCompletedPresent(User $user, string $date): void
    {
        WorkforceAttendanceDay::query()->updateOrCreate(
            ['user_id' => $user->id, 'work_date' => $date],
            [
                'status' => AttendanceDayStatus::Completed,
                'calendar_status' => WorkCalendarDayStatus::Working,
                'is_working_day' => true,
                'is_company_holiday' => false,
                'is_on_leave' => false,
                'has_schedule' => false,
                'session_count' => WorkSession::query()
                    ->where('user_id', $user->id)
                    ->whereDate('work_date', $date)
                    ->count(),
                'on_time_login' => true,
                'finalized_at' => Carbon::parse($date)->endOfDay(),
                'computed_at' => Carbon::parse($date)->endOfDay(),
                'source_version' => 1,
            ],
        );
    }

    private function seedExtra(User $user, string $date): void
    {
        WorkforceAttendanceDay::query()->create([
            'user_id' => $user->id,
            'work_date' => $date,
            'status' => AttendanceDayStatus::Extra,
            'calendar_status' => WorkCalendarDayStatus::WeeklyOff,
            'is_working_day' => false,
            'is_company_holiday' => false,
            'is_on_leave' => false,
            'has_schedule' => true,
            'session_count' => 0,
            'on_time_login' => null,
            'finalized_at' => Carbon::parse($date)->endOfDay(),
            'computed_at' => Carbon::parse($date)->endOfDay(),
            'source_version' => 1,
        ]);
    }

    private function seedClosedSession(User $user, string $date): void
    {
        WorkSession::query()->create([
            'user_id' => $user->id,
            'work_date' => $date,
            'login_at' => Carbon::parse($date.' 10:00:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse($date.' 14:00:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'session_duration_seconds' => 4 * 3600,
            'active_duration_seconds' => 4 * 3600,
            'on_time_login' => true,
        ]);
    }

    private function day(User $user, string $date): ?WorkforceAttendanceDay
    {
        return WorkforceAttendanceDay::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $date)
            ->first();
    }

    private function kind(User $user, string $date): AttendanceMatrixCellKind
    {
        return $this->mapper->kindFor(
            $this->day($user, $date),
            Carbon::parse($date),
            now()->startOfDay(),
        );
    }

    private function assertKind(User $user, string $date, AttendanceMatrixCellKind $expected): void
    {
        $this->assertSame(
            $expected,
            $this->kind($user, $date),
            sprintf('%s %s expected %s', $user->name, $date, $expected->value),
        );
    }
}
