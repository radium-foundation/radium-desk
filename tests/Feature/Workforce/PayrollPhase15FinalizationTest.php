<?php

namespace Tests\Feature\Workforce;

use App\Enums\AttendanceDayStatus;
use App\Enums\PayrollRunStatus;
use App\Enums\WorkCalendarDayStatus;
use App\Models\EmployeeSalary;
use App\Models\PayrollMonthRun;
use App\Models\PayrollRunLine;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use App\Services\Workforce\Payroll\EmployeeSalaryService;
use App\Services\Workforce\Payroll\PayrollRunService;
use App\Services\Workforce\PayrollMonthLockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PayrollPhase15FinalizationTest extends TestCase
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

    public function test_finalize_creates_immutable_snapshot(): void
    {
        $admin = $this->allowlistedAdmin();
        $agent = $this->makeAgent('Finalize Agent', withSchedule: true);
        $this->seedFullPresentMonth($agent, '2026-07');

        EmployeeSalary::query()->create([
            'user_id' => $agent->id,
            'monthly_salary' => 31000,
            'effective_from' => '2026-07-01',
            'is_active' => true,
        ]);

        $this->lockAttendanceMonth($admin, '2026-07-01');

        $run = app(PayrollRunService::class)->finalize(
            Carbon::parse('2026-07-01'),
            $admin,
            'July freeze',
        );

        $this->assertTrue($run->isFinalized());
        $this->assertSame(PayrollRunStatus::Finalized, $run->status);
        $this->assertSame(PayrollRunService::CALCULATION_VERSION, $run->calculation_version);
        $this->assertSame('July freeze', $run->notes);
        $this->assertNotNull($run->finalized_at);
        $this->assertSame($admin->id, $run->finalized_by);
        $this->assertSame(1, $run->lines()->count());

        $line = $run->lines()->first();
        $this->assertNotNull($line);
        $this->assertEquals(31000.0, (float) $line->monthly_salary_snapshot);
        $this->assertEquals(31, (int) $line->calendar_days);
        $this->assertEqualsWithDelta((float) $line->gross_salary, (float) $line->net_salary, 0.01);
        $this->assertIsArray($line->attendance_summary_json);
        $this->assertArrayHasKey('present', $line->attendance_summary_json);
    }

    public function test_salary_revision_after_finalize_does_not_change_snapshot(): void
    {
        $admin = $this->allowlistedAdmin();
        $agent = $this->makeAgent('Revise After', withSchedule: true);
        $this->seedFullPresentMonth($agent, '2026-07');

        $original = EmployeeSalary::query()->create([
            'user_id' => $agent->id,
            'monthly_salary' => 31000,
            'effective_from' => '2026-07-01',
            'is_active' => true,
        ]);

        $service = app(PayrollRunService::class);
        $this->lockAttendanceMonth($admin, '2026-07-01');
        $service->finalize(Carbon::parse('2026-07-01'), $admin);

        $frozenNet = (float) PayrollRunLine::query()->where('user_id', $agent->id)->value('net_salary');

        app(EmployeeSalaryService::class)->revise($original, [
            'monthly_salary' => 50000,
            'effective_from' => '2026-07-01',
            'is_active' => true,
        ]);

        $result = $service->resultForUser($agent, Carbon::parse('2026-07-01'));
        $this->assertNotNull($result);
        $this->assertTrue($result->isSnapshot);
        $this->assertEquals(31000.0, $result->monthlySalary);
        $this->assertEqualsWithDelta($frozenNet, $result->netSalary, 0.01);
    }

    public function test_attendance_change_after_finalize_does_not_change_snapshot(): void
    {
        $admin = $this->allowlistedAdmin();
        $agent = $this->makeAgent('Attend After', withSchedule: true);
        $this->seedFullPresentMonth($agent, '2026-07');

        EmployeeSalary::query()->create([
            'user_id' => $agent->id,
            'monthly_salary' => 31000,
            'effective_from' => '2026-07-01',
            'is_active' => true,
        ]);

        $service = app(PayrollRunService::class);
        $before = $service->resultForUser($agent, Carbon::parse('2026-07-01'));
        $this->assertNotNull($before);
        $payableBefore = $before->payableDays;

        $this->lockAttendanceMonth($admin, '2026-07-01');
        $service->finalize(Carbon::parse('2026-07-01'), $admin);

        // Flip a present day to absent after finalize.
        WorkforceAttendanceDay::query()
            ->where('user_id', $agent->id)
            ->whereDate('work_date', '2026-07-06')
            ->update([
                'status' => AttendanceDayStatus::NotStarted,
                'is_working_day' => true,
                'is_on_leave' => false,
            ]);

        $after = $service->resultForUser($agent, Carbon::parse('2026-07-01'));
        $this->assertNotNull($after);
        $this->assertTrue($after->isSnapshot);
        $this->assertEqualsWithDelta($payableBefore, $after->payableDays, 0.01);
        $this->assertEqualsWithDelta($before->netSalary, $after->netSalary, 0.01);
    }

    public function test_draft_payroll_still_reflects_live_values(): void
    {
        $agent = $this->makeAgent('Draft Live', withSchedule: true);
        $this->seedFullPresentMonth($agent, '2026-07');

        $salary = EmployeeSalary::query()->create([
            'user_id' => $agent->id,
            'monthly_salary' => 31000,
            'effective_from' => '2026-07-01',
            'is_active' => true,
        ]);

        $service = app(PayrollRunService::class);
        $draft = $service->resultForUser($agent, Carbon::parse('2026-07-01'));
        $this->assertNotNull($draft);
        $this->assertFalse($draft->isSnapshot);
        $this->assertEquals(31000.0, $draft->monthlySalary);

        app(EmployeeSalaryService::class)->revise($salary, [
            'monthly_salary' => 62000,
            'effective_from' => '2026-07-01',
            'is_active' => true,
        ]);

        $live = $service->resultForUser($agent, Carbon::parse('2026-07-01'));
        $this->assertNotNull($live);
        $this->assertFalse($live->isSnapshot);
        $this->assertEquals(62000.0, $live->monthlySalary);
        $this->assertEqualsWithDelta($draft->netSalary * 2, $live->netSalary, 0.02);
    }

    public function test_one_finalized_run_per_month(): void
    {
        $admin = $this->allowlistedAdmin();
        $agent = $this->makeAgent('Once Only', withSchedule: true);
        $this->seedFullPresentMonth($agent, '2026-07');

        EmployeeSalary::query()->create([
            'user_id' => $agent->id,
            'monthly_salary' => 31000,
            'effective_from' => '2026-07-01',
            'is_active' => true,
        ]);

        $service = app(PayrollRunService::class);
        $this->lockAttendanceMonth($admin, '2026-07-01');
        $service->finalize(Carbon::parse('2026-07-01'), $admin);

        $this->expectException(ValidationException::class);
        $service->finalize(Carbon::parse('2026-07-01'), $admin);
    }

    public function test_finalize_succeeds_when_attendance_locked(): void
    {
        $admin = $this->allowlistedAdmin();
        $agent = $this->makeAgent('Locked OK', withSchedule: true);
        $this->seedFullPresentMonth($agent, '2026-07');

        EmployeeSalary::query()->create([
            'user_id' => $agent->id,
            'monthly_salary' => 31000,
            'effective_from' => '2026-07-01',
            'is_active' => true,
        ]);

        $this->lockAttendanceMonth($admin, '2026-07-01');

        $run = app(PayrollRunService::class)->finalize(
            Carbon::parse('2026-07-01'),
            $admin,
        );

        $this->assertTrue($run->isFinalized());
        $this->assertSame(1, $run->lines()->count());
    }

    public function test_finalize_fails_when_attendance_unlocked(): void
    {
        $admin = $this->allowlistedAdmin();
        $agent = $this->makeAgent('Unlocked Fail', withSchedule: true);
        $this->seedFullPresentMonth($agent, '2026-07');

        EmployeeSalary::query()->create([
            'user_id' => $agent->id,
            'monthly_salary' => 31000,
            'effective_from' => '2026-07-01',
            'is_active' => true,
        ]);

        try {
            app(PayrollRunService::class)->finalize(
                Carbon::parse('2026-07-01'),
                $admin,
            );
            $this->fail('Expected ValidationException when attendance is unlocked.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                [PayrollRunService::ATTENDANCE_LOCK_REQUIRED_MESSAGE],
                $exception->errors()['month'] ?? null,
            );
        }

        $this->assertSame(0, PayrollMonthRun::query()->count());

        $this->actingAs($admin)
            ->post(route('workforce-management.payroll.finalize'), [
                'month' => '2026-07',
            ])
            ->assertSessionHasErrors([
                'month' => PayrollRunService::ATTENDANCE_LOCK_REQUIRED_MESSAGE,
            ]);

        $this->assertSame(0, PayrollMonthRun::query()->count());
    }

    public function test_finalized_payroll_detail_loads_snapshot_via_http(): void
    {
        $admin = $this->allowlistedAdmin();
        $agent = $this->makeAgent('HTTP Snapshot', withSchedule: true);
        $this->seedFullPresentMonth($agent, '2026-07');

        EmployeeSalary::query()->create([
            'user_id' => $agent->id,
            'monthly_salary' => 31000,
            'effective_from' => '2026-07-01',
            'is_active' => true,
        ]);

        $this->lockAttendanceMonth($admin, '2026-07-01');

        $this->actingAs($admin)
            ->post(route('workforce-management.payroll.finalize'), [
                'month' => '2026-07',
                'notes' => 'HTTP finalize',
            ])
            ->assertRedirect(route('workforce-management.payroll.index', ['month' => '2026-07']));

        $this->assertDatabaseHas('workforce_payroll_month_runs', [
            'status' => PayrollRunStatus::Finalized->value,
        ]);

        $this->actingAs($admin)
            ->get(route('workforce-management.payroll.index', ['month' => '2026-07']))
            ->assertOk()
            ->assertSee('Finalized Payroll')
            ->assertDontSee('Draft Payroll');

        $this->actingAs($admin)
            ->get(route('workforce-management.payroll.show', ['user' => $agent, 'month' => '2026-07']))
            ->assertOk()
            ->assertSee('Finalized Payroll')
            ->assertSee('Frozen snapshot');
    }

    public function test_finalize_authorization_admin_forbidden_operations_admin_allowed(): void
    {
        config([
            'workforce.attendance_management.allowed_emails' => [
                'info@radiumbox.com',
                'shipra@radiumbox.com',
                'admin.only@radiumbox.com',
            ],
        ]);

        $admin = $this->allowlistedAdminRole();
        $ops = $this->allowlistedOpsAdmin();
        $super = $this->allowlistedAdmin();
        $agent = $this->makeAgent('Auth Agent', withSchedule: true);
        $this->seedFullPresentMonth($agent, '2026-07');

        EmployeeSalary::query()->create([
            'user_id' => $agent->id,
            'monthly_salary' => 31000,
            'effective_from' => '2026-07-01',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('workforce-management.payroll.index', ['month' => '2026-07']))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('workforce-management.payroll.finalize'), [
                'month' => '2026-07',
            ])
            ->assertForbidden();

        $this->assertSame(0, PayrollMonthRun::query()->count());

        // Attendance lock remains Super Admin-only.
        $this->lockAttendanceMonth($super, '2026-07-01');

        $this->actingAs($ops)
            ->get(route('workforce-management.payroll.index', ['month' => '2026-07']))
            ->assertOk()
            ->assertSee('Finalize Payroll');

        $this->actingAs($ops)
            ->post(route('workforce-management.payroll.finalize'), [
                'month' => '2026-07',
                'notes' => 'Ops finalize',
            ])
            ->assertRedirect(route('workforce-management.payroll.index', ['month' => '2026-07']));

        $this->assertDatabaseHas('workforce_payroll_month_runs', [
            'status' => PayrollRunStatus::Finalized->value,
            'finalized_by' => $ops->id,
        ]);
    }

    public function test_operations_admin_cannot_reopen_payroll(): void
    {
        $ops = $this->allowlistedOpsAdmin();

        try {
            app(PayrollRunService::class)->reopen(Carbon::parse('2026-07-01'), $ops);
            $this->fail('Expected ValidationException for Operations Admin reopen.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Only Super Admin can reopen a finalized payroll month.'],
                $exception->errors()['month'] ?? null,
            );
        }
    }

    public function test_draft_index_shows_draft_label(): void
    {
        $admin = $this->allowlistedAdmin();
        $agent = $this->makeAgent('Draft UI', withSchedule: true);
        $this->seedFullPresentMonth($agent, '2026-07');

        EmployeeSalary::query()->create([
            'user_id' => $agent->id,
            'monthly_salary' => 31000,
            'effective_from' => '2026-07-01',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('workforce-management.payroll.index', ['month' => '2026-07']))
            ->assertOk()
            ->assertSee('Draft Payroll')
            ->assertSee('Lock attendance for this month before finalizing payroll.')
            ->assertDontSee('Finalize Payroll');
    }

    public function test_reopen_is_stubbed_for_super_admin(): void
    {
        $admin = $this->allowlistedAdmin();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not implemented');

        app(PayrollRunService::class)->reopen(Carbon::parse('2026-07-01'), $admin);
    }

    private function lockAttendanceMonth(User $admin, string $monthDay): void
    {
        app(PayrollMonthLockService::class)->lock(
            Carbon::parse($monthDay),
            $admin,
            'Test attendance lock',
        );
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

    private function allowlistedAdminRole(): User
    {
        $user = User::factory()->create([
            'name' => 'Regular Admin',
            'email' => 'admin.only@radiumbox.com',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user->fresh(['roles']);
    }

    private function allowlistedOpsAdmin(): User
    {
        $user = User::factory()->create([
            'name' => 'Ops Admin',
            'email' => 'shipra@radiumbox.com',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

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

    private function seedFullPresentMonth(User $user, string $ym): void
    {
        $start = Carbon::createFromFormat('Y-m', $ym)->startOfMonth();
        $days = $start->daysInMonth;

        for ($d = 1; $d <= $days; $d++) {
            $date = $start->copy()->day($d);
            $isSunday = $date->isSunday();

            WorkforceAttendanceDay::query()->create([
                'user_id' => $user->id,
                'work_date' => $date->toDateString(),
                'status' => $isSunday ? AttendanceDayStatus::ScheduledOff : AttendanceDayStatus::Completed,
                'calendar_status' => $isSunday ? WorkCalendarDayStatus::WeeklyOff : WorkCalendarDayStatus::Working,
                'is_working_day' => ! $isSunday,
                'is_company_holiday' => false,
                'is_on_leave' => false,
                'has_schedule' => true,
                'session_count' => $isSunday ? 0 : 1,
                'on_time_login' => $isSunday ? null : true,
                'finalized_at' => $date->copy()->endOfDay(),
                'computed_at' => $date->copy()->endOfDay(),
                'source_version' => 1,
            ]);
        }
    }
}
