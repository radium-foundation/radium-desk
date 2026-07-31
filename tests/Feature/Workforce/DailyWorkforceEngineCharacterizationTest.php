<?php

namespace Tests\Feature\Workforce;

use App\Contracts\Workforce\AttendancePolicy;
use App\Contracts\Workforce\CalendarPolicy;
use App\Contracts\Workforce\ContributionPolicy;
use App\Contracts\Workforce\IncentivePolicy;
use App\Contracts\Workforce\LeavePolicy;
use App\Contracts\Workforce\OvertimePolicy;
use App\Contracts\Workforce\PayrollPolicy;
use App\Contracts\Workforce\WorkforcePolicy;
use App\Enums\AttendanceDayStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use App\Models\WorkSession;
use App\Services\Operations\AttendanceRegisterService;
use App\Services\Operations\WorkCalendarService;
use App\Services\Workforce\DailyWorkforceEngine;
use App\Services\Workforce\MonthlyAttendanceMatrixService;
use App\Services\Workforce\Policies\CalendarPolicyAdapter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Milestone 1 characterization: DailyWorkforceEngine must match existing services exactly.
 */
class DailyWorkforceEngineCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceRegisterService $register;

    private MonthlyAttendanceMatrixService $matrix;

    private DailyWorkforceEngine $engine;

    private WorkCalendarService $calendar;

    private CalendarPolicy $calendarPolicy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->register = app(AttendanceRegisterService::class);
        $this->matrix = app(MonthlyAttendanceMatrixService::class);
        $this->engine = app(DailyWorkforceEngine::class);
        $this->calendar = app(WorkCalendarService::class);
        $this->calendarPolicy = app(CalendarPolicy::class);

        config([
            'presence.active_threshold_minutes' => 5,
            'presence.away_timeout_minutes' => 15,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_container_bindings_for_policy_ports_and_engine(): void
    {
        $this->assertInstanceOf(CalendarPolicyAdapter::class, $this->calendarPolicy);
        $this->assertInstanceOf(WorkforcePolicy::class, $this->calendarPolicy);
        $this->assertInstanceOf(DailyWorkforceEngine::class, $this->engine);
        $this->assertSame($this->calendarPolicy, $this->engine->calendar());

        $this->assertTrue(interface_exists(AttendancePolicy::class));
        $this->assertTrue(interface_exists(LeavePolicy::class));
        $this->assertTrue(interface_exists(ContributionPolicy::class));
        $this->assertTrue(interface_exists(OvertimePolicy::class));
        $this->assertTrue(interface_exists(IncentivePolicy::class));
        $this->assertTrue(interface_exists(PayrollPolicy::class));
    }

    public function test_calendar_policy_matches_work_calendar_service(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 12:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $at = Carbon::parse('2026-07-07 12:00:00', 'Asia/Kolkata');
        $schedule = $this->calendar->scheduleFor($agent);

        $this->assertNotNull($schedule);
        $this->assertSame(
            $this->calendar->defaultWeeklyOffDays(),
            $this->calendarPolicy->defaultWeeklyOffDays(),
        );
        $this->assertSame(
            $this->calendar->scheduleFor($agent)?->id,
            $this->calendarPolicy->scheduleFor($agent)?->id,
        );
        $this->assertSame(
            $this->calendar->scheduleFor($agent, $at)?->id,
            $this->calendarPolicy->scheduleFor($agent, $at)?->id,
        );
        $this->assertSame(
            $this->calendar->isCompanyHoliday($at),
            $this->calendarPolicy->isCompanyHoliday($at),
        );
        $this->assertSame(
            $this->calendar->hasApprovedLeave($agent, $at),
            $this->calendarPolicy->hasApprovedLeave($agent, $at),
        );
        $this->assertSame(
            $this->calendar->isWorkingDay($schedule, $at),
            $this->calendarPolicy->isWorkingDay($schedule, $at),
        );
        $this->assertSame(
            $this->calendar->expectedWorkingMinutes($schedule),
            $this->calendarPolicy->expectedWorkingMinutes($schedule),
        );
        $this->assertSame(
            $this->calendar->expectedWorkStartAt($schedule, $at)->toIso8601String(),
            $this->calendarPolicy->expectedWorkStartAt($schedule, $at)->toIso8601String(),
        );
        $this->assertSame(
            $this->calendar->expectedWorkEndAt($schedule, $at)->toIso8601String(),
            $this->calendarPolicy->expectedWorkEndAt($schedule, $at)->toIso8601String(),
        );
        $this->assertSame(
            $this->calendar->todayStatusFor($agent, $at),
            $this->calendarPolicy->todayStatusFor($agent, $at),
        );
        $this->assertSame(
            $this->calendar->isLateLogin($agent, Carbon::parse('2026-07-07 09:20:00', 'Asia/Kolkata')),
            $this->calendarPolicy->isLateLogin($agent, Carbon::parse('2026-07-07 09:20:00', 'Asia/Kolkata')),
        );
    }

    public function test_refresh_day_matches_register_service_exactly(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 18:30:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $this->seedCompletedSession($agent, '2026-07-07', '09:00:00', onTime: true);

        $viaRegister = $this->register->refreshDay($agent, Carbon::parse('2026-07-07'));
        $attributesAfterRegister = $this->attendanceSnapshot($viaRegister);

        WorkforceAttendanceDay::query()->where('user_id', $agent->id)->delete();

        $viaEngine = $this->engine->refreshDay($agent, Carbon::parse('2026-07-07'));
        $attributesAfterEngine = $this->attendanceSnapshot($viaEngine);

        $this->assertNotNull($viaRegister);
        $this->assertNotNull($viaEngine);
        $this->assertSame($attributesAfterRegister, $attributesAfterEngine);
        $this->assertSame(1, WorkforceAttendanceDay::query()->where('user_id', $agent->id)->count());
        $this->assertSame(AttendanceDayStatus::Completed, $viaEngine->status);
    }

    public function test_refresh_range_matches_register_service_exactly(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 18:30:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $this->seedCompletedSession($agent, '2026-07-07', '09:00:00', onTime: true);
        $this->seedCompletedSession($agent, '2026-07-08', '09:20:00', onTime: false);

        $from = Carbon::parse('2026-07-07');
        $to = Carbon::parse('2026-07-08');

        $registerCount = $this->register->refreshDateRange($agent, $from, $to);
        $registerRows = WorkforceAttendanceDay::query()
            ->where('user_id', $agent->id)
            ->orderBy('work_date')
            ->get()
            ->map(fn (WorkforceAttendanceDay $day) => $this->attendanceSnapshot($day))
            ->all();

        WorkforceAttendanceDay::query()->where('user_id', $agent->id)->delete();

        $engineCount = $this->engine->refreshRange($agent, $from, $to);
        $engineRows = WorkforceAttendanceDay::query()
            ->where('user_id', $agent->id)
            ->orderBy('work_date')
            ->get()
            ->map(fn (WorkforceAttendanceDay $day) => $this->attendanceSnapshot($day))
            ->all();

        $this->assertSame($registerCount, $engineCount);
        $this->assertSame($registerRows, $engineRows);
    }

    public function test_day_view_wraps_find_day_without_writing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 18:30:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $date = Carbon::parse('2026-07-07');

        $this->assertNull($this->engine->day($agent, $date));
        $this->assertNull($this->register->findDay($agent, $date));

        $this->seedCompletedSession($agent, '2026-07-07', '09:00:00', onTime: true);
        $persisted = $this->register->refreshDay($agent, $date);

        $found = $this->register->findDay($agent, $date);
        $view = $this->engine->day($agent, $date);

        $this->assertNotNull($persisted);
        $this->assertNotNull($found);
        $this->assertNotNull($view);
        $this->assertSame($found->id, $view->attendance()->id);
        $this->assertSame($found->status, $view->status());
        $this->assertSame((int) $found->user_id, $view->userId());
        $this->assertSame($found->work_date->toDateString(), $view->workDate()->toDateString());
        $this->assertSame(1, WorkforceAttendanceDay::query()->where('user_id', $agent->id)->count());
    }

    public function test_month_ledger_matches_matrix_build_for_user_exactly(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $this->seedCompletedSession($agent, '2026-07-07', '09:00:00', onTime: true);
        $this->seedCompletedSession($agent, '2026-07-08', '09:20:00', onTime: false);
        $this->register->refreshDateRange(
            $agent,
            Carbon::parse('2026-07-07'),
            Carbon::parse('2026-07-08'),
        );

        $month = Carbon::parse('2026-07-01');
        $at = Carbon::parse('2026-07-15 12:00:00', 'Asia/Kolkata');

        $matrixRow = $this->matrix->buildForUser($agent, $month, $at);
        $ledger = $this->engine->month($agent, $month, $at);

        $this->assertSame($matrixRow->userId, $ledger->userId());
        $this->assertSame($matrixRow->name, $ledger->name());
        $this->assertSame($matrixRow->roleLabel, $ledger->roleLabel());
        $this->assertSame($matrixRow->summary->presentDays, $ledger->summary()->presentDays);
        $this->assertSame($matrixRow->summary->absentDays, $ledger->summary()->absentDays);
        $this->assertSame($matrixRow->summary->leaveDays, $ledger->summary()->leaveDays);
        $this->assertSame($matrixRow->summary->lateDays, $ledger->summary()->lateDays);
        $this->assertSame($matrixRow->summary->holidayDays, $ledger->summary()->holidayDays);
        $this->assertSame($matrixRow->summary->weeklyOffDays, $ledger->summary()->weeklyOffDays);
        $this->assertSame($matrixRow->summary->extraDays, $ledger->summary()->extraDays);
        $this->assertSame($matrixRow->summary->activeDurationSeconds, $ledger->summary()->activeDurationSeconds);
        $this->assertSame($matrixRow->summary->overtimeSeconds, $ledger->summary()->overtimeSeconds);
        $this->assertSame($matrixRow->summary->hoursLabel, $ledger->summary()->hoursLabel);
        $this->assertSame($matrixRow->summary->overtimeLabel, $ledger->summary()->overtimeLabel);
        $this->assertSame(array_keys($matrixRow->cells), array_keys($ledger->cells()));

        foreach ($matrixRow->cells as $date => $cell) {
            $ledgerCell = $ledger->cells()[$date];
            $this->assertSame($cell->kind, $ledgerCell->kind);
            $this->assertSame($cell->shortLabel, $ledgerCell->shortLabel);
            $this->assertSame($cell->tone, $ledgerCell->tone);
            $this->assertSame($cell->tooltip, $ledgerCell->tooltip);
            $this->assertSame($cell->attendanceStatus, $ledgerCell->attendanceStatus);
        }

        $this->assertSame($matrixRow->userId, $ledger->memberRow()->userId);
        $this->assertSame('2026-07', $ledger->monthValue());
    }

    public function test_calculator_via_calendar_policy_preserves_late_and_extra_statuses(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 18:30:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $this->seedCompletedSession($agent, '2026-07-07', '09:20:00', onTime: false);

        $row = $this->engine->refreshDay($agent, Carbon::parse('2026-07-07'));
        $this->assertNotNull($row);
        $this->assertSame(AttendanceDayStatus::Late, $row->status);

        Carbon::setTestNow(Carbon::parse('2026-07-06 12:00:00', 'Asia/Kolkata'));
        $offAgent = $this->createScheduledAgent(weeklyOffDays: [Carbon::MONDAY]);
        WorkSession::query()->create([
            'user_id' => $offAgent->id,
            'work_date' => '2026-07-06',
            'login_at' => Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse('2026-07-06 12:00:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'session_duration_seconds' => 7200,
            'on_time_login' => null,
        ]);

        $extra = $this->engine->refreshDay($offAgent, Carbon::parse('2026-07-06'));
        $this->assertNotNull($extra);
        $this->assertSame(AttendanceDayStatus::Extra, $extra->status);
    }

    /**
     * @return array<string, mixed>
     */
    private function attendanceSnapshot(?WorkforceAttendanceDay $day): array
    {
        $this->assertNotNull($day);

        $attributes = $day->fresh()->getAttributes();
        unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);

        return $attributes;
    }

    private function seedCompletedSession(
        User $agent,
        string $date,
        string $loginTime,
        bool $onTime,
    ): void {
        $loginAt = Carbon::parse("{$date} {$loginTime}", 'Asia/Kolkata');
        $logoutAt = Carbon::parse("{$date} 18:00:00", 'Asia/Kolkata');

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => $date,
            'login_at' => $loginAt,
            'logout_at' => $logoutAt,
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'session_duration_seconds' => (int) $loginAt->diffInSeconds($logoutAt),
            'on_time_login' => $onTime,
        ]);
    }

    /**
     * @param  list<int>  $weeklyOffDays
     */
    private function createScheduledAgent(array $weeklyOffDays = [Carbon::SUNDAY]): User
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $agent->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => $weeklyOffDays,
        ]);

        return $agent->fresh(['workSchedule']);
    }
}
