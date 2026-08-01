<?php

namespace Tests\Feature\Workforce;

use App\Enums\WorkforceAuditEvent;
use App\Enums\WorkforceEventType;
use App\Models\LeaveRequest;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\Operations\AttendanceRegisterService;
use App\Services\Operations\CompanyHolidayService;
use App\Services\Operations\LeaveRequestService;
use App\Services\Workforce\DailyWorkforceEngine;
use App\Services\Workforce\PayrollMonthLockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PayrollMonthLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'presence.active_threshold_minutes' => 5,
            'presence.away_timeout_minutes' => 15,
            'workforce_calendar.retroactive_leave_days' => 60,
            'workforce.attendance_management.restricted' => true,
            'workforce.attendance_management.allowed_emails' => [
                'info@radiumbox.com',
                'shipra@radiumbox.com',
            ],
        ]);

        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-05 12:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_super_admin_can_lock_and_unlock_month(): void
    {
        $super = $this->superAdmin();
        $service = app(PayrollMonthLockService::class);
        $month = Carbon::parse('2026-07-01');

        $service->lock($month, $super, 'July payroll finalized');

        $this->assertTrue($service->isMonthLocked($month));
        $status = $service->statusForMonth($month);
        $this->assertTrue($status->isLocked());
        $this->assertSame($super->id, $status->lockedById);

        $this->assertDatabaseHas('audit_logs', [
            'event' => WorkforceAuditEvent::PayrollLocked->value,
            'user_id' => $super->id,
        ]);

        $service->unlock($month, $super, 'Weekly off correction');

        $this->assertFalse($service->isMonthLocked($month));
        $this->assertTrue($service->statusForMonth($month)->isOpen());

        $this->assertDatabaseHas('audit_logs', [
            'event' => WorkforceAuditEvent::PayrollUnlocked->value,
            'user_id' => $super->id,
        ]);
    }

    public function test_ops_admin_cannot_lock_month(): void
    {
        $ops = $this->opsAdmin();

        $this->expectException(ValidationException::class);

        app(PayrollMonthLockService::class)->lock(
            Carbon::parse('2026-07-01'),
            $ops,
            'should fail',
        );
    }

    public function test_ops_admin_cannot_post_lock_route(): void
    {
        $ops = $this->opsAdmin();

        $this->actingAs($ops)
            ->post(route('workforce-management.attendance.payroll-lock'), [
                'month' => '2026-07',
                'reason' => 'nope',
            ])
            ->assertForbidden();
    }

    public function test_super_admin_can_post_lock_and_unlock_routes(): void
    {
        $super = $this->superAdmin();

        $this->actingAs($super)
            ->post(route('workforce-management.attendance.payroll-lock'), [
                'month' => '2026-07',
                'reason' => 'Finalize July',
            ])
            ->assertRedirect(route('workforce-management.attendance.index', ['month' => '2026-07']))
            ->assertSessionHas('status', 'payroll-month-locked');

        $this->assertTrue(app(PayrollMonthLockService::class)->isMonthLocked(Carbon::parse('2026-07-01')));

        $this->actingAs($super)
            ->get(route('workforce-management.attendance.index', ['month' => '2026-07']))
            ->assertOk()
            ->assertSee('Locked', false)
            ->assertSee('Locked By', false);

        $this->actingAs($super)
            ->post(route('workforce-management.attendance.payroll-unlock'), [
                'month' => '2026-07',
                'reason' => 'Need correction',
            ])
            ->assertRedirect(route('workforce-management.attendance.index', ['month' => '2026-07']))
            ->assertSessionHas('status', 'payroll-month-unlocked');
    }

    public function test_leave_submit_approve_reject_blocked_when_month_locked(): void
    {
        $super = $this->superAdmin();
        $ops = $this->opsAdmin();
        $agent = $this->createScheduledAgent();
        $leaveService = app(LeaveRequestService::class);
        $lockService = app(PayrollMonthLockService::class);

        $pending = $leaveService->submit($agent, [
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-10',
            'reason' => 'Before lock',
        ]);

        $lockService->lock(Carbon::parse('2026-07-01'), $super, 'Locked');

        try {
            $leaveService->submit($agent, [
                'start_date' => '2026-07-11',
                'end_date' => '2026-07-11',
                'reason' => 'After lock',
            ]);
            $this->fail('Expected submit to fail for locked month.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('start_date', $e->errors());
        }

        try {
            $leaveService->approve($pending, $ops, 'Should block');
            $this->fail('Expected approve to fail for locked month.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('start_date', $e->errors());
        }

        try {
            $leaveService->reject($pending->fresh(), $ops, 'Should block');
            $this->fail('Expected reject to fail for locked month.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('start_date', $e->errors());
        }

        $this->assertSame(
            \App\Enums\LeaveRequestStatus::Pending,
            $pending->fresh()->status,
        );
    }

    public function test_refresh_day_soft_skips_locked_month_but_open_month_still_writes(): void
    {
        $super = $this->superAdmin();
        $agent = $this->createScheduledAgent();
        $register = app(AttendanceRegisterService::class);
        $july = Carbon::parse('2026-07-15');
        $august = Carbon::parse('2026-08-05');

        $julyDay = $register->refreshDay($agent, $july, $july->copy()->endOfDay(), allowPreShiftSkip: false);
        $this->assertNotNull($julyDay);
        $originalStatus = $julyDay->status;
        $originalComputed = $julyDay->computed_at?->toIso8601String();

        app(PayrollMonthLockService::class)->lock(Carbon::parse('2026-07-01'), $super, 'Lock July');

        $afterLock = $register->refreshDay($agent, $july, $july->copy()->endOfDay(), allowPreShiftSkip: false);
        $this->assertNotNull($afterLock);
        $this->assertSame($julyDay->id, $afterLock->id);
        $this->assertSame($originalStatus, $afterLock->status);
        $this->assertSame($originalComputed, $afterLock->fresh()->computed_at?->toIso8601String());

        $augustDay = $register->refreshDay($agent, $august, now(), allowPreShiftSkip: false);
        $this->assertNotNull($augustDay);
        $this->assertSame('2026-08-05', $augustDay->work_date->toDateString());
    }

    public function test_matrix_build_for_locked_month_does_not_throw(): void
    {
        $super = $this->superAdmin();
        $agent = $this->createScheduledAgent();
        $register = app(AttendanceRegisterService::class);

        $register->refreshDay(
            $agent,
            Carbon::parse('2026-07-15'),
            Carbon::parse('2026-07-15')->endOfDay(),
            allowPreShiftSkip: false,
        );

        app(PayrollMonthLockService::class)->lock(Carbon::parse('2026-07-01'), $super, 'Lock July');

        $report = app(DailyWorkforceEngine::class)->matrix(Carbon::parse('2026-07-01'));

        $this->assertSame('2026-07', $report->month->format('Y-m'));
        $this->assertNotEmpty($report->members);
    }

    public function test_company_holiday_blocked_in_locked_month(): void
    {
        $super = $this->superAdmin();
        app(PayrollMonthLockService::class)->lock(Carbon::parse('2026-07-01'), $super, 'Lock July');

        $this->expectException(ValidationException::class);

        app(CompanyHolidayService::class)->create([
            'holiday_date' => '2026-07-20',
            'name' => 'Test Holiday',
            'type' => 'company',
        ]);
    }

    public function test_future_month_remains_editable_when_prior_month_locked(): void
    {
        $super = $this->superAdmin();
        $agent = $this->createScheduledAgent();
        $leaveService = app(LeaveRequestService::class);

        app(PayrollMonthLockService::class)->lock(Carbon::parse('2026-07-01'), $super, 'Lock July');

        $created = $leaveService->submit($agent, [
            'start_date' => '2026-08-06',
            'end_date' => '2026-08-06',
            'reason' => 'August leave ok',
        ]);

        $this->assertInstanceOf(LeaveRequest::class, $created);
        $this->assertSame('2026-08-06', $created->start_date->toDateString());
    }

    public function test_payroll_locked_event_is_not_reserved(): void
    {
        $this->assertFalse(WorkforceEventType::PayrollLocked->isReserved());
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create([
            'email' => 'info@radiumbox.com',
            'is_active' => true,
            'first_name' => 'Ravi',
            'last_name' => 'Admin',
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }

    private function opsAdmin(): User
    {
        $user = User::factory()->create([
            'email' => 'shipra@radiumbox.com',
            'is_active' => true,
            'first_name' => 'Shipra',
            'last_name' => 'Ops',
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        return $user;
    }

    private function createScheduledAgent(): User
    {
        $agent = User::factory()->create([
            'is_active' => true,
            'first_name' => 'Agent',
            'last_name' => 'One',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $agent->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'weekly_off_days' => [0],
            'effective_from' => '2026-07-01',
        ]);

        return $agent->fresh(['workSchedule', 'roles']);
    }
}
