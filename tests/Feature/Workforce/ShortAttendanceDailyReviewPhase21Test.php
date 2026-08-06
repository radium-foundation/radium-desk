<?php

namespace Tests\Feature\Workforce;

use App\Enums\ShortAttendanceReviewDecision;
use App\Enums\ShortAttendanceReviewStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkSession;
use App\Models\WorkforceAttendanceDay;
use App\Models\WorkforceShortAttendanceReview;
use App\Notifications\ShortAttendanceEveningReviewNotification;
use App\Services\Operations\AttendanceRegisterService;
use App\Services\Workforce\Payroll\PayrollRunService;
use App\Services\Workforce\PayrollMonthLockService;
use App\Services\Workforce\ShortAttendance\ShortAttendanceReviewService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShortAttendanceDailyReviewPhase21Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'workforce.attendance_management.restricted' => false,
            'workforce_calendar.short_attendance_minutes' => 30,
            'workforce.short_attendance.reviewer_email' => 'shipra@radiumbox.com',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_today_widget_count_on_attendance_dashboard(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 10:00:00', 'Asia/Kolkata'));

        $hr = $this->makeHrReviewer();
        $agent = $this->createScheduledAgent();
        $this->seedShortAttendanceDay($agent, '2026-08-06', workedMinutes: 12);

        $this->actingAs($hr)
            ->get(route('workforce-management.attendance.index', ['month' => '2026-08']))
            ->assertOk()
            ->assertSee("Today's SA", false)
            ->assertSee('data-summary="sa-pending-today"', false);

        $this->assertSame(1, app(ShortAttendanceReviewService::class)->dashboardPendingCounts()['today']);
    }

    public function test_evening_notification_sent_when_pending_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 18:45:00', 'Asia/Kolkata'));
        Notification::fake();

        $hr = $this->makeHrReviewer();
        $agent = $this->createScheduledAgent();
        $this->seedShortAttendanceDay($agent, '2026-08-06', workedMinutes: 15);

        $result = app(ShortAttendanceReviewService::class)->sendEveningReviewNotification();

        $this->assertTrue($result['sent']);
        $this->assertSame(1, $result['pending_today']);
        Notification::assertSentTo($hr, ShortAttendanceEveningReviewNotification::class);
    }

    public function test_evening_notification_skipped_when_zero_pending(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 18:45:00', 'Asia/Kolkata'));
        Notification::fake();

        $hr = $this->makeHrReviewer();

        $result = app(ShortAttendanceReviewService::class)->sendEveningReviewNotification();

        $this->assertFalse($result['sent']);
        $this->assertSame(0, $result['pending_today']);
        Notification::assertNothingSent();
        $this->assertNotNull($hr);
    }

    public function test_morning_reminder_when_yesterday_still_pending(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 09:15:00', 'Asia/Kolkata'));

        $hr = $this->makeHrReviewer();
        $agent = $this->createScheduledAgent();
        $this->seedShortAttendanceDay($agent, '2026-08-05', workedMinutes: 10);

        $this->assertTrue(app(ShortAttendanceReviewService::class)->hasYesterdayPendingReminder());

        $this->actingAs($hr)
            ->get(route('workforce-management.short-attendance.index', [
                'period' => 'today',
                'status' => 'pending',
            ]))
            ->assertOk()
            ->assertSee('Morning reminder')
            ->assertSee('yesterday', false);
    }

    public function test_pending_filters_default_today_oldest_first(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 16:00:00', 'Asia/Kolkata'));

        $hr = $this->makeHrReviewer();
        $early = $this->createScheduledAgent('Early Agent');
        $late = $this->createScheduledAgent('Late Agent');

        $this->seedShortAttendanceDay($early, '2026-08-06', workedMinutes: 8);
        $this->seedShortAttendanceDay($late, '2026-08-06', workedMinutes: 14);

        $response = $this->actingAs($hr)
            ->get(route('workforce-management.short-attendance.index'))
            ->assertOk()
            ->assertSee('Early Agent')
            ->assertSee('Late Agent');

        $html = $response->getContent();
        $this->assertLessThan(
            strpos($html, 'Late Agent'),
            strpos($html, 'Early Agent'),
        );
    }

    public function test_decide_advances_to_next_pending(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 16:00:00', 'Asia/Kolkata'));

        $hr = $this->makeHrReviewer();
        $first = $this->createScheduledAgent('First Case');
        $second = $this->createScheduledAgent('Second Case');
        $this->seedShortAttendanceDay($first, '2026-08-06', workedMinutes: 9);
        $this->seedShortAttendanceDay($second, '2026-08-06', workedMinutes: 11);

        $firstReview = WorkforceShortAttendanceReview::query()
            ->where('user_id', $first->id)
            ->firstOrFail();
        $secondReview = WorkforceShortAttendanceReview::query()
            ->where('user_id', $second->id)
            ->firstOrFail();

        $this->actingAs($hr)
            ->post(route('workforce-management.short-attendance.decide', $firstReview), [
                'decision' => ShortAttendanceReviewDecision::KeepShortAttendance->value,
                'decision_reason' => 'No proof',
                'period' => 'today',
                'status' => 'pending',
            ])
            ->assertRedirect(route('workforce-management.short-attendance.show', [
                'review' => $secondReview,
                'period' => 'today',
                'status' => 'pending',
            ]));
    }

    public function test_payroll_lock_blocked_when_pending_reviews_exist(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 20:00:00', 'Asia/Kolkata'));

        $super = User::factory()->create(['is_active' => true]);
        $super->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $agent = $this->createScheduledAgent();
        $this->seedShortAttendanceDay($agent, '2026-08-06', workedMinutes: 16);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cannot finalize payroll');

        app(PayrollMonthLockService::class)->lock(
            Carbon::parse('2026-08-01'),
            $super,
            'August lock',
        );
    }

    public function test_payroll_lock_allowed_when_no_pending_reviews(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 20:00:00', 'Asia/Kolkata'));

        $super = User::factory()->create(['is_active' => true]);
        $super->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
        $hr = $this->makeHrReviewer();
        $agent = $this->createScheduledAgent();
        $this->seedShortAttendanceDay($agent, '2026-08-06', workedMinutes: 16);
        $review = WorkforceShortAttendanceReview::query()->firstOrFail();

        app(ShortAttendanceReviewService::class)->decide(
            review: $review,
            actor: $hr,
            decision: ShortAttendanceReviewDecision::KeepShortAttendance,
            reason: 'Confirmed no work',
        );

        $lock = app(PayrollMonthLockService::class)->lock(
            Carbon::parse('2026-08-01'),
            $super,
            'August lock',
        );

        $this->assertTrue($lock->isCurrentlyLocked());
    }

    public function test_payroll_finalize_blocked_when_pending_reviews_exist(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 20:00:00', 'Asia/Kolkata'));

        $super = User::factory()->create(['is_active' => true]);
        $super->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $agent = $this->createScheduledAgent();
        $this->seedShortAttendanceDay($agent, '2026-08-06', workedMinutes: 18);
        $review = WorkforceShortAttendanceReview::query()->firstOrFail();
        $hr = $this->makeHrReviewer();
        app(ShortAttendanceReviewService::class)->decide(
            review: $review,
            actor: $hr,
            decision: ShortAttendanceReviewDecision::KeepShortAttendance,
            reason: 'Cleared for lock',
        );

        app(PayrollMonthLockService::class)->lock(Carbon::parse('2026-08-01'), $super, 'Locked');

        // Month is locked — insert a pending review directly (register refresh is write-blocked).
        $other = $this->createScheduledAgent('Pending Later');
        WorkforceShortAttendanceReview::query()->create([
            'user_id' => $other->id,
            'work_date' => '2026-08-06',
            'status' => ShortAttendanceReviewStatus::PendingReview,
            'worked_minutes' => 7,
            'previous_status' => 'short_attendance',
            'calculated_reason' => 'short_attendance',
            'session_count' => 1,
            'away_timeout_count' => 1,
            'had_auto_logout' => true,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cannot finalize payroll');

        app(PayrollRunService::class)->finalize(Carbon::parse('2026-08-01'), $super);
    }

    public function test_phase1_register_and_phase2_decision_semantics_unchanged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 18:00:00', 'Asia/Kolkata'));

        $hr = $this->makeHrReviewer();
        $agent = $this->createScheduledAgent();
        $this->seedShortAttendanceDay($agent, '2026-08-06', workedMinutes: 20);
        $review = WorkforceShortAttendanceReview::query()->firstOrFail();

        app(ShortAttendanceReviewService::class)->decide(
            review: $review,
            actor: $hr,
            decision: ShortAttendanceReviewDecision::ApproveFullDay,
            reason: 'Field work verified',
        );

        $day = WorkforceAttendanceDay::query()->where('user_id', $agent->id)->firstOrFail();
        $review->refresh();

        $this->assertSame('short_attendance', $day->status->value);
        $this->assertSame(ShortAttendanceReviewStatus::Decided, $review->status);
        $this->assertSame(ShortAttendanceReviewDecision::ApproveFullDay, $review->decision);
        $this->assertSame('present', $review->new_status);
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

    private function createScheduledAgent(string $name = 'Agent'): User
    {
        $agent = User::factory()->create([
            'is_active' => true,
            'name' => $name,
            'department' => 'Support',
        ]);
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

        $this->assertTrue(
            WorkforceShortAttendanceReview::query()
                ->where('user_id', $agent->id)
                ->whereDate('work_date', $date)
                ->where('status', ShortAttendanceReviewStatus::PendingReview)
                ->exists(),
        );
    }
}
