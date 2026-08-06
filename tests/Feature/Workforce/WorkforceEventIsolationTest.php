<?php

namespace Tests\Feature\Workforce;

use App\Contracts\Workforce\WorkforceEventPublisher;
use App\Data\Workforce\WorkforceEvent;
use App\Enums\AttendanceDayStatus;
use App\Enums\LeaveRequestStatus;
use App\Enums\WorkSessionEndReason;
use App\Enums\WorkforceEventType;
use App\Models\LeaveRequest;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use App\Models\WorkSession;
use App\Services\Operations\AttendanceRegisterService;
use App\Services\Operations\LeaveRequestService;
use App\Services\Workforce\Events\NullWorkforceEventPublisher;
use App\Services\Workforce\Events\SafeWorkforceEventPublisher;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * Milestone 3: WorkforceEvent is additive; publishing must not alter attendance.
 */
class WorkforceEventIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

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

    public function test_default_publisher_is_safe_null_noop(): void
    {
        $publisher = app(WorkforceEventPublisher::class);

        $this->assertInstanceOf(SafeWorkforceEventPublisher::class, $publisher);

        $publisher->publish(WorkforceEvent::make(
            type: WorkforceEventType::AttendanceRecorded,
            userId: 1,
        ));

        $this->assertTrue(true);
    }

    public function test_attendance_snapshot_identical_with_null_and_recording_publishers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 18:30:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $this->seedCompletedSession($agent, '2026-07-07', '09:00:00', onTime: true);

        $this->bindInnerPublisher(new NullWorkforceEventPublisher);
        $nullDay = app(AttendanceRegisterService::class)->refreshDay($agent, Carbon::parse('2026-07-07'));
        $nullSnapshot = $this->attendanceSnapshot($nullDay);

        WorkforceAttendanceDay::query()->where('user_id', $agent->id)->delete();

        $recording = new RecordingWorkforceEventPublisher;
        $this->bindInnerPublisher($recording);
        $recordedDay = app(AttendanceRegisterService::class)->refreshDay($agent, Carbon::parse('2026-07-07'));
        $recordedSnapshot = $this->attendanceSnapshot($recordedDay);

        $this->assertSame($nullSnapshot, $recordedSnapshot);
        $this->assertSame(AttendanceDayStatus::Completed, $recordedDay->status);
        $this->assertCount(1, $recording->events);
        $this->assertSame(WorkforceEventType::AttendanceRecorded, $recording->events[0]->type);
        $this->assertSame($agent->id, $recording->events[0]->userId);
        $this->assertSame('2026-07-07', $recording->events[0]->workDate?->toDateString());
    }

    public function test_throwing_publisher_cannot_affect_attendance_writes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 18:30:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $this->seedCompletedSession($agent, '2026-07-07', '09:20:00', onTime: false);

        $this->bindInnerPublisher(new ThrowingWorkforceEventPublisher);

        $day = app(AttendanceRegisterService::class)->refreshDay($agent, Carbon::parse('2026-07-07'));

        $this->assertNotNull($day);
        $this->assertSame(AttendanceDayStatus::Late, $day->status);
        $this->assertSame(1, WorkforceAttendanceDay::query()->where('user_id', $agent->id)->count());
        $this->assertDatabaseHas('workforce_attendance_days', [
            'user_id' => $agent->id,
            'status' => AttendanceDayStatus::Late->value,
        ]);
    }

    public function test_leave_approve_publishes_after_success_without_changing_attendance_math(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);
        $opsAdmin = User::factory()->create([
            'email' => 'shipra@radiumbox.com',
            'is_active' => true,
        ]);
        $opsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $leave = LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => '2026-07-09',
            'end_date' => '2026-07-09',
            'reason' => 'Personal',
            'status' => LeaveRequestStatus::Pending,
        ]);

        $recording = new RecordingWorkforceEventPublisher;
        $this->bindInnerPublisher($recording);

        app(LeaveRequestService::class)->approve($leave, $opsAdmin, 'Approved for planned leave');

        $day = WorkforceAttendanceDay::query()
            ->where('user_id', $agent->id)
            ->whereDate('work_date', '2026-07-09')
            ->first();

        $this->assertNotNull($day);
        $this->assertSame(AttendanceDayStatus::OnLeave, $day->status);

        $types = array_map(
            static fn (WorkforceEvent $event): string => $event->type->value,
            $recording->events,
        );

        $this->assertContains(WorkforceEventType::LeaveApproved->value, $types);
        $this->assertContains(WorkforceEventType::AttendanceRecorded->value, $types);
        $this->assertNotContains(WorkforceEventType::ContributionQualified->value, $types);
        $this->assertNotContains(WorkforceEventType::PayrollLocked->value, $types);
        $this->assertNotContains(WorkforceEventType::SalesCredited->value, $types);
        $this->assertNotContains(WorkforceEventType::PerformanceCalculated->value, $types);
    }

    public function test_leave_reject_publishes_leave_rejected_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);
        $opsAdmin = User::factory()->create([
            'email' => 'shipra@radiumbox.com',
            'is_active' => true,
        ]);
        $opsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $leave = LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => '2026-07-11',
            'end_date' => '2026-07-11',
            'reason' => 'Personal',
            'status' => LeaveRequestStatus::Pending,
        ]);

        $recording = new RecordingWorkforceEventPublisher;
        $this->bindInnerPublisher($recording);

        app(LeaveRequestService::class)->reject($leave, $opsAdmin, 'Rejected — coverage needed');

        $this->assertCount(1, $recording->events);
        $this->assertSame(WorkforceEventType::LeaveRejected, $recording->events[0]->type);
        $this->assertSame(0, WorkforceAttendanceDay::query()->where('user_id', $agent->id)->count());
    }

    public function test_reserved_event_types_exist_but_are_not_produced_yet(): void
    {
        $this->assertTrue(WorkforceEventType::LeaveCancelled->isReserved());
        $this->assertTrue(WorkforceEventType::HalfDayRecorded->isReserved());
        $this->assertFalse(WorkforceEventType::PayrollLocked->isReserved());
        $this->assertFalse(WorkforceEventType::WeeklyOffWorked->isReserved());
        $this->assertFalse(WorkforceEventType::HolidayWorked->isReserved());
        $this->assertFalse(WorkforceEventType::RecognitionRecommended->isReserved());
        $this->assertFalse(WorkforceEventType::RecognitionDecided->isReserved());
        $this->assertTrue(WorkforceEventType::IncentiveAwarded->isReserved());
        $this->assertFalse(WorkforceEventType::AttendanceRecorded->isReserved());
        $this->assertFalse(WorkforceEventType::LeaveApproved->isReserved());
        $this->assertFalse(WorkforceEventType::LeaveRejected->isReserved());
        $this->assertFalse(WorkforceEventType::ContributionQualified->isReserved());
    }

    private function bindInnerPublisher(WorkforceEventPublisher $inner): void
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
            'active_duration_seconds' => 28800,
            'on_time_login' => $onTime,
        ]);
    }

    private function createScheduledAgent(): User
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
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        return $agent->fresh(['workSchedule']);
    }
}

final class RecordingWorkforceEventPublisher implements WorkforceEventPublisher
{
    /** @var list<WorkforceEvent> */
    public array $events = [];

    public function publish(WorkforceEvent $event): void
    {
        $this->events[] = $event;
    }
}

final class ThrowingWorkforceEventPublisher implements WorkforceEventPublisher
{
    public function publish(WorkforceEvent $event): void
    {
        throw new RuntimeException('Publisher failure must not affect attendance.');
    }
}
