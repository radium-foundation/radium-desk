<?php

namespace Tests\Feature\Workforce;

use App\Enums\AttendanceDayStatus;
use App\Enums\AttendanceMatrixCellKind;
use App\Enums\ShortAttendanceReviewDecision;
use App\Enums\ShortAttendanceReviewStatus;
use App\Enums\WorkforceAuditEvent;
use App\Enums\WorkSessionEndReason;
use App\Models\AuditLog;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkSession;
use App\Models\WorkforceAttendanceDay;
use App\Models\WorkforceShortAttendanceReview;
use App\Services\Operations\AttendanceRegisterService;
use App\Services\Workforce\Payroll\PayrollPayableDayPolicy;
use App\Services\Workforce\ShortAttendance\ShortAttendanceReviewService;
use App\Support\Workforce\AttendanceMatrixCellMapper;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShortAttendanceReviewPhase2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'workforce.attendance_management.restricted' => false,
            'workforce_calendar.short_attendance_minutes' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_short_attendance_appears_in_review_queue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 18:30:00', 'Asia/Kolkata'));

        $hr = $this->makeHrReviewer();
        $agent = $this->createScheduledAgent();
        $this->seedShortAttendanceDay($agent, '2026-08-05', workedMinutes: 18);

        $this->actingAs($hr)
            ->get(route('workforce-management.short-attendance.index', [
                'period' => 'today',
                'status' => 'pending',
            ]))
            ->assertOk()
            ->assertSee('Short Attendance Review')
            ->assertSee($agent->name)
            ->assertSee('18 min')
            ->assertSee('Pending Today');
    }

    public function test_hr_can_approve_full_day_and_payroll_uses_present(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 18:30:00', 'Asia/Kolkata'));

        $hr = $this->makeHrReviewer();
        $agent = $this->createScheduledAgent();
        $this->seedShortAttendanceDay($agent, '2026-08-05', workedMinutes: 12);

        $review = WorkforceShortAttendanceReview::query()->firstOrFail();

        $this->actingAs($hr)
            ->post(route('workforce-management.short-attendance.decide', $review), [
                'decision' => ShortAttendanceReviewDecision::ApproveFullDay->value,
                'decision_reason' => 'Verified field visit proof',
                'decision_note' => 'Manager confirmed',
                'period' => 'today',
                'status' => 'pending',
            ])
            ->assertRedirect(route('workforce-management.short-attendance.index', [
                'period' => 'today',
                'status' => 'pending',
            ]));

        $review->refresh();
        $day = WorkforceAttendanceDay::query()->where('user_id', $agent->id)->firstOrFail();

        $this->assertSame(AttendanceDayStatus::ShortAttendance, $day->status);
        $this->assertSame(ShortAttendanceReviewStatus::Decided, $review->status);
        $this->assertSame(ShortAttendanceReviewDecision::ApproveFullDay, $review->decision);
        $this->assertSame('short_attendance', $review->previous_status);
        $this->assertSame('present', $review->new_status);

        $kind = app(AttendanceMatrixCellMapper::class)->kindFor(
            $day,
            Carbon::parse('2026-08-05'),
            Carbon::parse('2026-08-05'),
            app(ShortAttendanceReviewService::class)->decidedOverrideForDay($agent->id, Carbon::parse('2026-08-05')),
        );
        $this->assertSame(AttendanceMatrixCellKind::Present, $kind);
        $this->assertSame(1.0, $kind->payableDayFraction());

        $audit = AuditLog::query()
            ->where('event', WorkforceAuditEvent::ShortAttendanceReviewDecided->value)
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame($hr->id, $audit->user_id);
        $this->assertSame('short_attendance', $audit->new_values['previous_status'] ?? null);
        $this->assertSame('present', $audit->new_values['new_status'] ?? null);
        $this->assertSame('Verified field visit proof', $audit->new_values['decision_reason'] ?? null);
    }

    public function test_hr_can_approve_half_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 18:30:00', 'Asia/Kolkata'));

        $hr = $this->makeHrReviewer();
        $agent = $this->createScheduledAgent();
        $this->seedShortAttendanceDay($agent, '2026-08-05', workedMinutes: 20);
        $review = WorkforceShortAttendanceReview::query()->firstOrFail();

        $this->actingAs($hr)
            ->post(route('workforce-management.short-attendance.decide', $review), [
                'decision' => ShortAttendanceReviewDecision::ApproveHalfDay->value,
                'decision_reason' => 'Partial day approved',
            ])
            ->assertRedirect();

        $override = app(ShortAttendanceReviewService::class)
            ->decidedOverrideForDay($agent->id, Carbon::parse('2026-08-05'));

        $this->assertSame(AttendanceMatrixCellKind::HalfDay, $override);
        $this->assertSame(0.5, $override->payableDayFraction());
    }

    public function test_hr_can_keep_short_attendance(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 18:30:00', 'Asia/Kolkata'));

        $hr = $this->makeHrReviewer();
        $agent = $this->createScheduledAgent();
        $this->seedShortAttendanceDay($agent, '2026-08-05', workedMinutes: 8);
        $review = WorkforceShortAttendanceReview::query()->firstOrFail();

        $this->actingAs($hr)
            ->post(route('workforce-management.short-attendance.decide', $review), [
                'decision' => ShortAttendanceReviewDecision::KeepShortAttendance->value,
                'decision_reason' => 'No justification provided',
            ])
            ->assertRedirect();

        $review->refresh();
        $this->assertSame(ShortAttendanceReviewDecision::KeepShortAttendance, $review->decision);
        $this->assertSame('short_attendance', $review->new_status);

        $day = WorkforceAttendanceDay::query()->where('user_id', $agent->id)->firstOrFail();
        $kind = app(AttendanceMatrixCellMapper::class)->kindFor(
            $day,
            Carbon::parse('2026-08-05'),
            Carbon::parse('2026-08-05'),
            app(ShortAttendanceReviewService::class)->decidedOverrideForDay($agent->id, Carbon::parse('2026-08-05')),
        );
        $this->assertSame(AttendanceMatrixCellKind::ShortAttendance, $kind);

        $breakdown = app(PayrollPayableDayPolicy::class)->summarize([
            '2026-08-05' => new \App\Data\Workforce\AttendanceMatrixCell(
                userId: $agent->id,
                workDate: '2026-08-05',
                kind: $kind,
                shortLabel: $kind->shortLabel(),
                tone: $kind->tone(),
                tooltip: $kind->label(),
                interactive: true,
                disabled: false,
                attendanceStatus: $day->status,
                drawerPayload: [],
            ),
        ]);
        $this->assertSame(1, $breakdown['absent']);
        $this->assertEqualsWithDelta(1.0, $breakdown['non_payable_days'], 0.01);
    }

    public function test_unreviewed_short_attendance_remains_short_attendance_for_payroll(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 18:30:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $this->seedShortAttendanceDay($agent, '2026-08-05', workedMinutes: 15);

        $day = WorkforceAttendanceDay::query()->where('user_id', $agent->id)->firstOrFail();
        $kind = app(AttendanceMatrixCellMapper::class)->kindFor(
            $day,
            Carbon::parse('2026-08-05'),
            Carbon::parse('2026-08-05'),
        );

        $this->assertSame(AttendanceMatrixCellKind::ShortAttendance, $kind);
        $this->assertSame(0.0, $kind->payableDayFraction());
    }

    public function test_unauthorized_users_cannot_approve(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 18:30:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $this->seedShortAttendanceDay($agent, '2026-08-05', workedMinutes: 10);
        $review = WorkforceShortAttendanceReview::query()->firstOrFail();

        $this->actingAs($agent)
            ->post(route('workforce-management.short-attendance.decide', $review), [
                'decision' => ShortAttendanceReviewDecision::ApproveFullDay->value,
                'decision_reason' => 'Self approve',
            ])
            ->assertForbidden();

        $this->actingAs($agent)
            ->get(route('workforce-management.short-attendance.index'))
            ->assertForbidden();
    }

    public function test_phase1_register_status_unchanged_after_override(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 18:30:00', 'Asia/Kolkata'));

        $hr = $this->makeHrReviewer();
        $agent = $this->createScheduledAgent();
        $this->seedShortAttendanceDay($agent, '2026-08-05', workedMinutes: 22);
        $review = WorkforceShortAttendanceReview::query()->firstOrFail();

        $this->actingAs($hr)->post(route('workforce-management.short-attendance.decide', $review), [
            'decision' => ShortAttendanceReviewDecision::MarkLeave->value,
            'decision_reason' => 'Leave proof received',
        ])->assertRedirect();

        $day = WorkforceAttendanceDay::query()->where('user_id', $agent->id)->firstOrFail();
        $this->assertSame(AttendanceDayStatus::ShortAttendance, $day->status);
        $this->assertSame('short_attendance', $day->status_reason);

        $override = app(ShortAttendanceReviewService::class)
            ->decidedOverrideForDay($agent->id, Carbon::parse('2026-08-05'));
        $this->assertSame(AttendanceMatrixCellKind::Leave, $override);
    }

    private function makeHrReviewer(): User
    {
        $hr = User::factory()->create([
            'is_active' => true,
            'email' => 'shipra@radiumbox.com',
        ]);
        $hr->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        return $hr;
    }

    private function createScheduledAgent(): User
    {
        $agent = User::factory()->create(['is_active' => true, 'department' => 'Support']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $agent->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        return $agent->fresh(['workSchedule']);
    }

    private function seedShortAttendanceDay(User $agent, string $date, int $workedMinutes): void
    {
        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => $date,
            'login_at' => Carbon::parse("{$date} 09:00:00", 'Asia/Kolkata'),
            'logout_at' => Carbon::parse("{$date} 09:".str_pad((string) min(59, $workedMinutes), 2, '0', STR_PAD_LEFT).':00', 'Asia/Kolkata'),
            'last_activity_at' => Carbon::parse("{$date} 09:".str_pad((string) max(0, min(59, $workedMinutes - 1)), 2, '0', STR_PAD_LEFT).':00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::AwayTimeout,
            'session_duration_seconds' => $workedMinutes * 60,
            'active_duration_seconds' => $workedMinutes * 60,
            'on_time_login' => true,
            'is_attributable' => true,
        ]);

        app(AttendanceRegisterService::class)->refreshDay(
            $agent,
            Carbon::parse($date),
            Carbon::parse("{$date} 18:30:00", 'Asia/Kolkata'),
            allowPreShiftSkip: false,
        );

        $day = WorkforceAttendanceDay::query()
            ->where('user_id', $agent->id)
            ->whereDate('work_date', $date)
            ->first();

        $this->assertNotNull($day);
        $this->assertSame(AttendanceDayStatus::ShortAttendance, $day->status);
        $this->assertTrue(
            WorkforceShortAttendanceReview::query()
                ->where('user_id', $agent->id)
                ->whereDate('work_date', $date)
                ->where('status', ShortAttendanceReviewStatus::PendingReview)
                ->exists(),
        );
    }
}
