<?php

namespace Tests\Feature\Workforce;

use App\Data\Workforce\AttendanceMatrixCell;
use App\Enums\AttendanceDayStatus;
use App\Enums\AttendanceMatrixCellKind;
use App\Enums\CompanyHolidayType;
use App\Enums\LeaveDuration;
use App\Enums\LeaveRequestStatus;
use App\Enums\WorkCalendarDayStatus;
use App\Models\CompanyHoliday;
use App\Models\EmployeeSalary;
use App\Models\LeaveRequest;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use App\Services\Workforce\MonthlyAttendanceMatrixService;
use App\Services\Workforce\Payroll\EmployeeSalaryService;
use App\Services\Workforce\Payroll\PayrollCalculationService;
use App\Services\Workforce\PayrollMonthLockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PayrollPhase1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'presence.active_threshold_minutes' => 5,
            'presence.away_timeout_minutes' => 15,
            'workforce.attendance_management.restricted' => true,
            'workforce.attendance_management.allowed_emails' => [
                'info@radiumbox.com',
                'shipra@radiumbox.com',
            ],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-05 12:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_salary_for_month_uses_latest_active_effective_on_or_before_month_end(): void
    {
        $agent = $this->makeAgent('Salary Agent');
        $service = app(EmployeeSalaryService::class);

        EmployeeSalary::query()->create([
            'user_id' => $agent->id,
            'monthly_salary' => 20000,
            'effective_from' => '2026-06-01',
            'is_active' => true,
        ]);
        EmployeeSalary::query()->create([
            'user_id' => $agent->id,
            'monthly_salary' => 30000,
            'effective_from' => '2026-07-15',
            'is_active' => true,
        ]);
        EmployeeSalary::query()->create([
            'user_id' => $agent->id,
            'monthly_salary' => 40000,
            'effective_from' => '2026-08-01',
            'is_active' => true,
        ]);

        $july = $service->salaryForMonth($agent, Carbon::parse('2026-07-01'));
        $this->assertNotNull($july);
        $this->assertEquals(30000.0, (float) $july->monthly_salary);
    }

    public function test_inactive_salary_is_ignored(): void
    {
        $agent = $this->makeAgent('Inactive Salary');
        $service = app(EmployeeSalaryService::class);

        EmployeeSalary::query()->create([
            'user_id' => $agent->id,
            'monthly_salary' => 50000,
            'effective_from' => '2026-07-01',
            'is_active' => false,
        ]);

        $this->assertNull($service->salaryForMonth($agent, Carbon::parse('2026-07-01')));
    }

    public function test_payroll_payable_rules_and_net_salary(): void
    {
        $agent = $this->makeAgent('Payroll Math', withSchedule: true);

        EmployeeSalary::query()->create([
            'user_id' => $agent->id,
            'monthly_salary' => 31000,
            'effective_from' => '2026-07-01',
            'is_active' => true,
        ]);

        // Seed a controlled mini-month via classifyCells for rule clarity,
        // then full-month calc with seeded register days for Jul 1–5.
        CompanyHoliday::query()->create([
            'holiday_date' => '2026-07-03',
            'name' => 'Test Holiday',
            'type' => CompanyHolidayType::Company,
        ]);
        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => '2026-07-02',
            'end_date' => '2026-07-02',
            'reason' => 'Paid leave',
            'duration' => LeaveDuration::FullDay,
            'status' => LeaveRequestStatus::Approved,
        ]);
        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => '2026-07-04',
            'end_date' => '2026-07-04',
            'reason' => 'Half',
            'duration' => LeaveDuration::HalfDay,
            'status' => LeaveRequestStatus::Approved,
        ]);

        $this->seedDay($agent, '2026-07-01', AttendanceDayStatus::Completed, onTime: true); // Present Wed
        $this->seedDay($agent, '2026-07-02', AttendanceDayStatus::OnLeave, onLeave: true); // Leave
        $this->seedDay($agent, '2026-07-03', AttendanceDayStatus::ScheduledOff, holiday: true); // Holiday
        $this->seedDay($agent, '2026-07-04', AttendanceDayStatus::HalfDay, onLeave: true); // Half
        $this->seedDay($agent, '2026-07-05', AttendanceDayStatus::ScheduledOff, working: false); // Sunday WO

        // Rest of July: Present working days, Absent one day, Extra one Sunday with sessions pattern
        for ($d = 6; $d <= 31; $d++) {
            $date = sprintf('2026-07-%02d', $d);
            $carbon = Carbon::parse($date);
            if ($carbon->isSunday()) {
                $this->seedDay($agent, $date, AttendanceDayStatus::ScheduledOff, working: false);
            } else {
                $this->seedDay($agent, $date, AttendanceDayStatus::Completed, onTime: true);
            }
        }

        // Override Jul 6 as Absent, Jul 12 Extra (Sunday work ignored)
        $this->seedDay($agent, '2026-07-06', AttendanceDayStatus::NotStarted); // Absent
        $this->seedDay($agent, '2026-07-12', AttendanceDayStatus::Extra, working: false);

        $result = app(PayrollCalculationService::class)
            ->calculateForUser($agent, Carbon::parse('2026-07-01'));

        $this->assertNotNull($result);
        $this->assertSame(31, $result->calendarDays);
        $this->assertSame(1, $result->absentDays);
        $this->assertSame(1, $result->extraDays);
        $this->assertSame(1.0, $result->nonPayableDays);

        // Payable: all days except Absent(1) and Extra(1 ignored) and Future/Empty
        // Jul has 31 days: 1 absent + 1 extra ignored → payable from remaining
        // Extra doesn't add payable or non-payable. Absent = 1 non-payable.
        // Days: 31 total cells. Extra day is still a cell but ignored in payable.
        // So payable days = sum of fractions on non-extra, non-future cells that are payable.
        // Jul 12 Extra: ignored (0). Jul 6 Absent: non-payable.
        // Half day Jul 4: 0.5. All other non-extra days payable at 1.0.
        // Count: 31 - 1 (extra) - 1 (absent) = 29 full + 0.5 half... wait half is included in the 29?
        // Days: all 31 classified. Extra ignored. Absent non-payable. Half = 0.5. Rest payable 1.0.
        // Number of 1.0 days = 31 - 1 extra - 1 absent - 1 half = 28
        // Payable = 28 + 0.5 = 28.5
        $this->assertEqualsWithDelta(28.5, $result->payableDays, 0.01);

        $expectedNet = round((31000 / 31) * 28.5, 2);
        $this->assertEqualsWithDelta($expectedNet, $result->netSalary, 0.01);

        // Matrix payableDays must remain attendance rule (not payroll rule).
        $matrixRow = app(MonthlyAttendanceMatrixService::class)
            ->buildForUser($agent, Carbon::parse('2026-07-01'));
        // Leave/WO/Holiday contribute 0 to matrix payableDays; Present/Late=1; Half=0.5
        $this->assertLessThan($result->payableDays, $matrixRow->summary->payableDays);
    }

    public function test_classify_cells_extra_ignored_leave_and_off_payable(): void
    {
        $service = app(PayrollCalculationService::class);
        $cells = [
            $this->fakeCell(AttendanceMatrixCellKind::Present),
            $this->fakeCell(AttendanceMatrixCellKind::Leave),
            $this->fakeCell(AttendanceMatrixCellKind::WeeklyOff),
            $this->fakeCell(AttendanceMatrixCellKind::Holiday),
            $this->fakeCell(AttendanceMatrixCellKind::HalfDay),
            $this->fakeCell(AttendanceMatrixCellKind::Absent),
            $this->fakeCell(AttendanceMatrixCellKind::Extra),
            $this->fakeCell(AttendanceMatrixCellKind::Late),
        ];

        $breakdown = $service->classifyCells($cells);

        $this->assertEqualsWithDelta(5.5, $breakdown['payable_days'], 0.01); // P+V+W+N+H0.5+L
        $this->assertEqualsWithDelta(1.0, $breakdown['non_payable_days'], 0.01);
        $this->assertSame(1, $breakdown['extra']);
    }

    public function test_short_attendance_is_payroll_absent(): void
    {
        $service = app(PayrollCalculationService::class);
        $cells = [
            $this->fakeCell(AttendanceMatrixCellKind::Present),
            $this->fakeCell(AttendanceMatrixCellKind::ShortAttendance),
            $this->fakeCell(AttendanceMatrixCellKind::Absent),
        ];

        $breakdown = $service->classifyCells($cells);

        $this->assertEqualsWithDelta(1.0, $breakdown['payable_days'], 0.01);
        $this->assertEqualsWithDelta(2.0, $breakdown['non_payable_days'], 0.01);
        $this->assertSame(2, $breakdown['absent']);
        $this->assertSame(0.0, AttendanceMatrixCellKind::ShortAttendance->payableDayFraction());
    }

    public function test_payroll_index_and_detail_access(): void
    {
        $admin = $this->allowlistedAdmin();
        $agent = $this->makeAgent('Visible Agent', withSchedule: true);

        EmployeeSalary::query()->create([
            'user_id' => $agent->id,
            'monthly_salary' => 25000,
            'effective_from' => '2026-07-01',
            'is_active' => true,
        ]);
        $this->seedDay($agent, '2026-07-01', AttendanceDayStatus::Completed, onTime: true);

        $this->actingAs($admin)
            ->get(route('workforce-management.payroll.index', ['month' => '2026-07']))
            ->assertOk()
            ->assertSee('Visible Agent')
            ->assertSee('Payable Days');

        $this->actingAs($admin)
            ->get(route('workforce-management.payroll.show', ['user' => $agent, 'month' => '2026-07']))
            ->assertOk()
            ->assertSee('Salary calculation')
            ->assertSee('Visible Agent');

        $outsider = User::factory()->create([
            'email' => 'outsider@example.test',
            'is_active' => true,
        ]);
        $outsider->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($outsider)
            ->get(route('workforce-management.payroll.index', ['month' => '2026-07']))
            ->assertForbidden();
    }

    public function test_locked_month_still_allows_payroll_view(): void
    {
        $admin = $this->allowlistedAdmin();
        $agent = $this->makeAgent('Locked Month Agent', withSchedule: true);

        EmployeeSalary::query()->create([
            'user_id' => $agent->id,
            'monthly_salary' => 20000,
            'effective_from' => '2026-07-01',
            'is_active' => true,
        ]);
        $this->seedDay($agent, '2026-07-01', AttendanceDayStatus::Completed, onTime: true);

        app(PayrollMonthLockService::class)->lock(
            Carbon::parse('2026-07-01'),
            $admin,
            'Phase 1 view test',
        );

        $this->actingAs($admin)
            ->get(route('workforce-management.payroll.index', ['month' => '2026-07']))
            ->assertOk()
            ->assertSee('Locked');

        $this->actingAs($admin)
            ->get(route('workforce-management.payroll.show', ['user' => $agent, 'month' => '2026-07']))
            ->assertOk();
    }

    public function test_salary_store_via_http(): void
    {
        $admin = $this->allowlistedAdmin();
        $agent = $this->makeAgent('HTTP Salary');

        $this->actingAs($admin)
            ->post(route('workforce-management.salaries.store'), [
                'user_id' => $agent->id,
                'monthly_salary' => 45000,
                'effective_from' => '2026-07-01',
                'is_active' => '1',
            ])
            ->assertRedirect(route('workforce-management.salaries.index'));

        $this->assertDatabaseHas('workforce_employee_salaries', [
            'user_id' => $agent->id,
            'monthly_salary' => 45000,
        ]);
    }

    public function test_salary_revise_is_append_only(): void
    {
        $admin = $this->allowlistedAdmin();
        $agent = $this->makeAgent('Revise Salary');
        $service = app(EmployeeSalaryService::class);

        $original = $service->create([
            'user_id' => $agent->id,
            'monthly_salary' => 20000,
            'effective_from' => '2026-07-01',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('workforce-management.salaries.revise', $original), [
                'monthly_salary' => 25000,
                'effective_from' => '2026-08-01',
                'is_active' => '1',
            ])
            ->assertRedirect(route('workforce-management.salaries.index'));

        $this->assertDatabaseHas('workforce_employee_salaries', [
            'id' => $original->id,
            'monthly_salary' => 20000,
        ]);
        $this->assertTrue(
            $original->fresh()->effective_from->toDateString() === '2026-07-01'
        );
        $this->assertDatabaseHas('workforce_employee_salaries', [
            'user_id' => $agent->id,
            'monthly_salary' => 25000,
        ]);
        $this->assertSame(2, EmployeeSalary::query()->where('user_id', $agent->id)->count());
        $this->assertTrue(
            EmployeeSalary::query()
                ->where('user_id', $agent->id)
                ->where('monthly_salary', 25000)
                ->first()
                ?->effective_from
                ?->toDateString() === '2026-08-01'
        );

        $july = $service->salaryForMonth($agent, Carbon::parse('2026-07-01'));
        $august = $service->salaryForMonth($agent, Carbon::parse('2026-08-01'));
        $this->assertEquals(20000.0, (float) $july?->monthly_salary);
        $this->assertEquals(25000.0, (float) $august?->monthly_salary);
    }

    public function test_unpaid_leave_is_non_payable_paid_leave_unchanged(): void
    {
        $agent = $this->makeAgent('LOP Agent', withSchedule: true);

        EmployeeSalary::query()->create([
            'user_id' => $agent->id,
            'monthly_salary' => 31000,
            'effective_from' => '2026-07-01',
            'is_active' => true,
        ]);

        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => '2026-07-02',
            'end_date' => '2026-07-02',
            'reason' => 'Unpaid',
            'duration' => LeaveDuration::FullDay,
            'pay_class' => \App\Enums\LeavePayClass::Unpaid,
            'status' => LeaveRequestStatus::Approved,
        ]);
        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => '2026-07-03',
            'end_date' => '2026-07-03',
            'reason' => 'Paid',
            'duration' => LeaveDuration::FullDay,
            'pay_class' => \App\Enums\LeavePayClass::Paid,
            'status' => LeaveRequestStatus::Approved,
        ]);

        $this->seedDay($agent, '2026-07-02', AttendanceDayStatus::OnLeave, onLeave: true);
        $this->seedDay($agent, '2026-07-03', AttendanceDayStatus::OnLeave, onLeave: true);
        $this->seedDay($agent, '2026-07-01', AttendanceDayStatus::Completed, onTime: true);

        for ($d = 4; $d <= 31; $d++) {
            $date = sprintf('2026-07-%02d', $d);
            $carbon = Carbon::parse($date);
            if ($carbon->isSunday()) {
                $this->seedDay($agent, $date, AttendanceDayStatus::ScheduledOff, working: false);
            } else {
                $this->seedDay($agent, $date, AttendanceDayStatus::Completed, onTime: true);
            }
        }

        $result = app(PayrollCalculationService::class)
            ->calculateForUser($agent, Carbon::parse('2026-07-01'));

        $this->assertNotNull($result);
        // 31 days: 1 unpaid leave non-payable, rest payable (incl paid leave + Sundays)
        $this->assertEqualsWithDelta(1.0, $result->nonPayableDays, 0.01);
        $this->assertEqualsWithDelta(30.0, $result->payableDays, 0.01);
        $this->assertSame(2, $result->leaveDays);
    }

    private function allowlistedAdmin(): User
    {
        $user = User::factory()->create([
            'name' => 'Payroll Admin',
            'email' => 'info@radiumbox.com',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user->fresh(['roles']);
    }

    private function makeAgent(string $name, bool $withSchedule = false): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        if ($withSchedule) {
            TeamMemberWorkSchedule::query()->create([
                'user_id' => $user->id,
                'work_start_time' => '09:00:00',
                'work_end_time' => '18:00:00',
                'weekly_off_days' => [Carbon::SUNDAY],
                'effective_from' => '2000-01-01',
            ]);
        }

        return $user->fresh(['roles']);
    }

    private function seedDay(
        User $user,
        string $date,
        AttendanceDayStatus $status,
        bool $onTime = false,
        bool $onLeave = false,
        bool $holiday = false,
        bool $working = true,
    ): void {
        $existing = WorkforceAttendanceDay::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $date)
            ->first();

        $attributes = [
            'user_id' => $user->id,
            'work_date' => $date,
            'status' => $status,
            'calendar_status' => match (true) {
                $holiday => WorkCalendarDayStatus::Holiday,
                $onLeave => WorkCalendarDayStatus::LeaveApproved,
                ! $working => WorkCalendarDayStatus::WeeklyOff,
                default => WorkCalendarDayStatus::Working,
            },
            'is_working_day' => $working && ! $holiday,
            'is_company_holiday' => $holiday,
            'is_on_leave' => $onLeave,
            'has_schedule' => true,
            'session_count' => $status === AttendanceDayStatus::Extra ? 1 : 0,
            'on_time_login' => $onTime ? true : null,
            'finalized_at' => Carbon::parse($date)->endOfDay(),
            'computed_at' => Carbon::parse($date)->endOfDay(),
            'source_version' => 1,
        ];

        if ($existing !== null) {
            $existing->fill($attributes)->save();

            return;
        }

        WorkforceAttendanceDay::query()->create($attributes);
    }

    private function fakeCell(AttendanceMatrixCellKind $kind): AttendanceMatrixCell
    {
        return new AttendanceMatrixCell(
            userId: 1,
            workDate: '2026-07-01',
            kind: $kind,
            shortLabel: $kind->shortLabel(),
            tone: $kind->tone(),
            tooltip: $kind->label(),
            interactive: true,
            disabled: false,
            attendanceStatus: null,
            drawerPayload: [],
        );
    }
}
