<?php

namespace Tests\Feature\Workforce;

use App\Contracts\Workforce\WorkforceEventPublisher;
use App\Data\Workforce\WorkforceEvent;
use App\Enums\AttendanceDayStatus;
use App\Enums\ContributionVerdict;
use App\Enums\ExtraQualificationReason;
use App\Enums\LeaveRequestStatus;
use App\Enums\WorkSessionEndReason;
use App\Enums\WorkforceEventType;
use App\Models\CompanyHoliday;
use App\Models\LeaveRequest;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use App\Models\WorkSession;
use App\Enums\CompanyHolidayType;
use App\Services\Operations\AttendanceRegisterService;
use App\Services\Workforce\Events\SafeWorkforceEventPublisher;
use App\Services\Workforce\Extra\ExtraQualificationEngine;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Milestone 5: Extra qualification is a separate policy layer; Attendance is never mutated.
 */
class ExtraQualificationEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'presence.active_threshold_minutes' => 5,
            'presence.away_timeout_minutes' => 15,
            'workforce_contribution.enabled' => false,
            'workforce.extra_qualification.enabled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_disabled_flag_mirrors_today_extra_without_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 12:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent(weeklyOffDays: [Carbon::MONDAY]);
        $this->seedSession($agent, '2026-07-06', activeSeconds: 0, casesHandled: 0);

        $day = app(AttendanceRegisterService::class)->refreshDay($agent, Carbon::parse('2026-07-06'));
        $this->assertSame(AttendanceDayStatus::Extra, $day->status);

        $recording = new ExtraQualificationRecordingPublisher;
        $this->bindPublisher($recording);

        $decision = app(ExtraQualificationEngine::class)->evaluate($agent, Carbon::parse('2026-07-06'));

        $this->assertFalse($decision->engineEnabled);
        $this->assertTrue($decision->qualified);
        $this->assertSame(ExtraQualificationReason::FeatureDisabled, $decision->reason);
        $this->assertSame([], $recording->events);
        $this->assertSame(
            $this->attendanceSnapshot($day),
            $this->attendanceSnapshot(WorkforceAttendanceDay::query()->whereKey($day->id)->first()),
        );
    }

    public function test_working_day_never_becomes_ex_when_enabled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 18:30:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $this->seedSession($agent, '2026-07-07', activeSeconds: 14400, casesHandled: 5);
        $day = app(AttendanceRegisterService::class)->refreshDay($agent, Carbon::parse('2026-07-07'));
        $before = $this->attendanceSnapshot($day);

        $this->enableEngines();
        $decision = app(ExtraQualificationEngine::class)->evaluate($agent, Carbon::parse('2026-07-07'));

        $this->assertFalse($decision->qualified);
        $this->assertSame(ExtraQualificationReason::WorkingDayNeverExtra, $decision->reason);
        $this->assertSame('WorkingDay', $decision->proposedOutcome);
        $this->assertSame($before, $this->attendanceSnapshot($day->fresh()));
        $this->assertNotSame(AttendanceDayStatus::Extra, $day->fresh()->status);
    }

    public function test_weekly_off_low_contribution_not_qualified(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 18:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent(weeklyOffDays: [Carbon::MONDAY]);
        $this->seedSession($agent, '2026-07-06', activeSeconds: 60, casesHandled: 0);
        $day = app(AttendanceRegisterService::class)->refreshDay($agent, Carbon::parse('2026-07-06'));
        $before = $this->attendanceSnapshot($day);

        $this->enableEngines();
        $recording = new ExtraQualificationRecordingPublisher;
        $this->bindPublisher($recording);
        $this->app->forgetInstance(ExtraQualificationEngine::class);

        $decision = app(ExtraQualificationEngine::class)->evaluate($agent, Carbon::parse('2026-07-06'));

        $this->assertSame(ContributionVerdict::Low, $decision->contributionVerdict);
        $this->assertFalse($decision->qualified);
        $this->assertSame(ExtraQualificationReason::WeeklyOffInsufficientContribution, $decision->reason);
        $this->assertSame('WO', $decision->proposedOutcome);
        $this->assertSame([], $recording->events);
        $this->assertSame($before, $this->attendanceSnapshot($day->fresh()));
        // Attendance SoT may still show Extra today — qualification layer says WO.
        $this->assertSame(AttendanceDayStatus::Extra, $day->fresh()->status);
    }

    public function test_weekly_off_qualified_contribution_earns_ex(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 18:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent(weeklyOffDays: [Carbon::MONDAY]);
        $this->seedSession($agent, '2026-07-06', activeSeconds: 2000, casesHandled: 1);
        $day = app(AttendanceRegisterService::class)->refreshDay($agent, Carbon::parse('2026-07-06'));
        $before = $this->attendanceSnapshot($day);

        $this->enableEngines();
        $recording = new ExtraQualificationRecordingPublisher;
        $this->bindPublisher($recording);
        $this->app->forgetInstance(ExtraQualificationEngine::class);

        $decision = app(ExtraQualificationEngine::class)->evaluate($agent, Carbon::parse('2026-07-06'));

        $this->assertTrue($decision->contributionVerdict?->isQualified());
        $this->assertTrue($decision->qualified);
        $this->assertSame(ExtraQualificationReason::WeeklyOffQualified, $decision->reason);
        $this->assertSame('EX', $decision->proposedOutcome);
        $extraEvents = array_values(array_filter(
            $recording->events,
            static fn (WorkforceEvent $event): bool => $event->type === WorkforceEventType::ExtraDayEarned,
        ));
        $this->assertCount(1, $extraEvents);
        $this->assertSame($before, $this->attendanceSnapshot($day->fresh()));
    }

    public function test_holiday_qualified_contribution_earns_ex(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04 18:00:00', 'Asia/Kolkata'));

        CompanyHoliday::query()->create([
            'name' => 'Test Holiday',
            'holiday_date' => '2026-07-04',
            'type' => CompanyHolidayType::National,
        ]);

        $agent = $this->createScheduledAgent();
        $this->seedSession($agent, '2026-07-04', activeSeconds: 2000, casesHandled: 2);
        $day = app(AttendanceRegisterService::class)->refreshDay($agent, Carbon::parse('2026-07-04'));
        $before = $this->attendanceSnapshot($day);

        $this->enableEngines();
        $decision = app(ExtraQualificationEngine::class)->evaluate($agent, Carbon::parse('2026-07-04'));

        $this->assertTrue($day->is_company_holiday);
        $this->assertTrue($decision->qualified);
        $this->assertSame(ExtraQualificationReason::HolidayQualified, $decision->reason);
        $this->assertSame('EX', $decision->proposedOutcome);
        $this->assertSame($before, $this->attendanceSnapshot($day->fresh()));
    }

    public function test_leave_never_qualifies_as_ex(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 12:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => '2026-07-09',
            'end_date' => '2026-07-09',
            'reason' => 'Personal',
            'status' => LeaveRequestStatus::Approved,
        ]);

        $this->seedSession($agent, '2026-07-09', activeSeconds: 5000, casesHandled: 5);
        $day = app(AttendanceRegisterService::class)->refreshDay($agent, Carbon::parse('2026-07-09'));
        $before = $this->attendanceSnapshot($day);

        $this->enableEngines();
        $recording = new ExtraQualificationRecordingPublisher;
        $this->bindPublisher($recording);
        $this->app->forgetInstance(ExtraQualificationEngine::class);

        $decision = app(ExtraQualificationEngine::class)->evaluate($agent, Carbon::parse('2026-07-09'));

        $this->assertSame(AttendanceDayStatus::OnLeave, $day->status);
        $this->assertFalse($decision->qualified);
        $this->assertSame(ExtraQualificationReason::LeaveNeverExtra, $decision->reason);
        $this->assertSame('Leave', $decision->proposedOutcome);
        $extraEvents = array_values(array_filter(
            $recording->events,
            static fn (WorkforceEvent $event): bool => $event->type === WorkforceEventType::ExtraDayEarned,
        ));
        $this->assertSame([], $extraEvents);
        $this->assertSame($before, $this->attendanceSnapshot($day->fresh()));
    }

    public function test_weekly_off_no_work_remains_wo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 18:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent(weeklyOffDays: [Carbon::MONDAY]);
        $day = app(AttendanceRegisterService::class)->refreshDay($agent, Carbon::parse('2026-07-06'));
        $before = $this->attendanceSnapshot($day);

        $this->enableEngines();
        $decision = app(ExtraQualificationEngine::class)->evaluate($agent, Carbon::parse('2026-07-06'));

        $this->assertSame(AttendanceDayStatus::ScheduledOff, $day->status);
        $this->assertFalse($decision->qualified);
        $this->assertSame(ExtraQualificationReason::WeeklyOffNoWork, $decision->reason);
        $this->assertSame('WO', $decision->proposedOutcome);
        $this->assertSame($before, $this->attendanceSnapshot($day->fresh()));
    }

    private function enableEngines(): void
    {
        config([
            'workforce_contribution.enabled' => true,
            'workforce.extra_qualification.enabled' => true,
        ]);
        $this->app->forgetInstance(ExtraQualificationEngine::class);
        $this->app->forgetInstance(\App\Services\Workforce\Contribution\ContributionEngine::class);
    }

    private function bindPublisher(WorkforceEventPublisher $inner): void
    {
        $this->app->instance('workforce.events.inner_publisher', $inner);
        $this->app->instance(
            WorkforceEventPublisher::class,
            new SafeWorkforceEventPublisher($inner),
        );
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

    private function seedSession(
        User $agent,
        string $date,
        int $activeSeconds,
        int $casesHandled,
    ): void {
        $loginAt = Carbon::parse("{$date} 10:00:00", 'Asia/Kolkata');
        $logoutAt = Carbon::parse("{$date} 12:00:00", 'Asia/Kolkata');

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => $date,
            'login_at' => $loginAt,
            'logout_at' => $logoutAt,
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'session_duration_seconds' => (int) $loginAt->diffInSeconds($logoutAt),
            'active_duration_seconds' => $activeSeconds,
            'cases_handled_count' => $casesHandled,
            'communication_events_count' => 0,
            'resolution_events_count' => 0,
            'on_time_login' => null,
            'is_attributable' => true,
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

        return $agent->fresh(['workSchedule', 'roles']);
    }
}

final class ExtraQualificationRecordingPublisher implements WorkforceEventPublisher
{
    /** @var list<WorkforceEvent> */
    public array $events = [];

    public function publish(WorkforceEvent $event): void
    {
        $this->events[] = $event;
    }
}
