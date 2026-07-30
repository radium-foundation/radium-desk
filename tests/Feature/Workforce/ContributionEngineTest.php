<?php

namespace Tests\Feature\Workforce;

use App\Contracts\Workforce\WorkforceEventPublisher;
use App\Data\Workforce\WorkforceEvent;
use App\Enums\AttendanceDayStatus;
use App\Enums\ContributionSignalId;
use App\Enums\ContributionVerdict;
use App\Enums\WorkSessionEndReason;
use App\Enums\WorkforceEventType;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use App\Models\WorkSession;
use App\Services\Operations\AttendanceRegisterService;
use App\Services\Workforce\Contribution\ContributionEngine;
use App\Services\Workforce\Events\SafeWorkforceEventPublisher;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Milestone 4: Contribution Engine is independent of Attendance and flag-gated.
 */
class ContributionEngineTest extends TestCase
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
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_disabled_flag_returns_none_and_publishes_nothing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 18:30:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $this->seedSession($agent, activeSeconds: 7200, casesHandled: 3);

        $recording = new ContributionRecordingPublisher;
        $this->bindPublisher($recording);

        $evaluation = app(ContributionEngine::class)->evaluate($agent, Carbon::parse('2026-07-07'));

        $this->assertFalse($evaluation->engineEnabled);
        $this->assertSame(ContributionVerdict::None, $evaluation->verdict);
        $this->assertFalse($evaluation->isQualified());
        $this->assertSame([], $recording->events);
        $this->assertStringContainsString('disabled', $evaluation->reasons[0]);
    }

    public function test_evaluation_does_not_modify_attendance_snapshots(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 18:30:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $this->seedSession($agent, activeSeconds: 7200, casesHandled: 2);

        $day = app(AttendanceRegisterService::class)->refreshDay($agent, Carbon::parse('2026-07-07'));
        $before = $this->attendanceSnapshot($day);
        $rowCountBefore = WorkforceAttendanceDay::query()->count();

        config(['workforce_contribution.enabled' => true]);
        $this->app->forgetInstance(ContributionEngine::class);

        $evaluation = app(ContributionEngine::class)->evaluate($agent, Carbon::parse('2026-07-07'));

        $after = $this->attendanceSnapshot(
            WorkforceAttendanceDay::query()->where('user_id', $agent->id)->first()
        );

        $this->assertSame($before, $after);
        $this->assertSame($rowCountBefore, WorkforceAttendanceDay::query()->count());
        $this->assertSame(AttendanceDayStatus::Completed, $day->status);
        $this->assertTrue($evaluation->engineEnabled);
        $this->assertTrue($evaluation->verdict->isQualified());
    }

    public function test_disabled_path_matches_today_attendance_behaviour(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 18:30:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $this->seedSession($agent, activeSeconds: 0, casesHandled: 0, loginTime: '09:00:00');

        config(['workforce_contribution.enabled' => false]);
        app(ContributionEngine::class)->evaluate($agent, Carbon::parse('2026-07-07'));

        $day = app(AttendanceRegisterService::class)->refreshDay($agent, Carbon::parse('2026-07-07'));

        $this->assertNotNull($day);
        $this->assertSame(AttendanceDayStatus::Completed, $day->status);
    }

    public function test_support_pack_verdicts_from_existing_metrics(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 18:30:00', 'Asia/Kolkata'));
        config(['workforce_contribution.enabled' => true]);
        $this->app->forgetInstance(ContributionEngine::class);

        $agent = $this->createScheduledAgent();

        $this->seedSession($agent, activeSeconds: 0, casesHandled: 0);
        $none = app(ContributionEngine::class)->evaluate($agent, Carbon::parse('2026-07-07'));
        $this->assertSame(ContributionVerdict::None, $none->verdict);

        WorkSession::query()->where('user_id', $agent->id)->delete();
        $this->seedSession($agent, activeSeconds: 600, casesHandled: 0);
        $low = app(ContributionEngine::class)->evaluate($agent, Carbon::parse('2026-07-07'));
        $this->assertSame(ContributionVerdict::Low, $low->verdict);
        $this->assertNotEmpty($low->thresholdsFailed);

        WorkSession::query()->where('user_id', $agent->id)->delete();
        $this->seedSession($agent, activeSeconds: 1800, casesHandled: 0);
        $normal = app(ContributionEngine::class)->evaluate($agent, Carbon::parse('2026-07-07'));
        $this->assertSame(ContributionVerdict::Normal, $normal->verdict);
        $this->assertContains('active_duration:normal', $normal->thresholdsMet);

        WorkSession::query()->where('user_id', $agent->id)->delete();
        $this->seedSession($agent, activeSeconds: 14400, casesHandled: 5);
        $high = app(ContributionEngine::class)->evaluate($agent, Carbon::parse('2026-07-07'));
        $this->assertSame(ContributionVerdict::High, $high->verdict);
        $this->assertSame('support_agent', $high->pack->id);
    }

    public function test_manager_pack_resolved_for_operations_admin(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 18:30:00', 'Asia/Kolkata'));
        config(['workforce_contribution.enabled' => true]);
        $this->app->forgetInstance(ContributionEngine::class);

        $manager = User::factory()->create(['is_active' => true]);
        $manager->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
        TeamMemberWorkSchedule::query()->create([
            'user_id' => $manager->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        $evaluation = app(ContributionEngine::class)->evaluate(
            $manager->fresh(['roles', 'workSchedule']),
            Carbon::parse('2026-07-07'),
        );

        $this->assertSame('manager', $evaluation->pack->id);
        $this->assertSame('Manager', $evaluation->pack->label);
    }

    public function test_qualified_evaluation_publishes_contribution_qualified_when_enabled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 18:30:00', 'Asia/Kolkata'));
        config(['workforce_contribution.enabled' => true]);

        $agent = $this->createScheduledAgent();
        $this->seedSession($agent, activeSeconds: 2000, casesHandled: 1);

        $recording = new ContributionRecordingPublisher;
        $this->bindPublisher($recording);
        $this->app->forgetInstance(ContributionEngine::class);

        $evaluation = app(ContributionEngine::class)->evaluate($agent, Carbon::parse('2026-07-07'));

        $this->assertTrue($evaluation->isQualified());
        $this->assertCount(1, $recording->events);
        $this->assertSame(WorkforceEventType::ContributionQualified, $recording->events[0]->type);
        $this->assertSame($agent->id, $recording->events[0]->userId);
    }

    public function test_low_verdict_does_not_publish_contribution_qualified(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 18:30:00', 'Asia/Kolkata'));
        config(['workforce_contribution.enabled' => true]);

        $agent = $this->createScheduledAgent();
        $this->seedSession($agent, activeSeconds: 60, casesHandled: 0);

        $recording = new ContributionRecordingPublisher;
        $this->bindPublisher($recording);
        $this->app->forgetInstance(ContributionEngine::class);

        $evaluation = app(ContributionEngine::class)->evaluate($agent, Carbon::parse('2026-07-07'));

        $this->assertSame(ContributionVerdict::Low, $evaluation->verdict);
        $this->assertSame([], $recording->events);
    }

    public function test_reserved_signals_are_unavailable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 18:30:00', 'Asia/Kolkata'));
        config(['workforce_contribution.enabled' => true]);
        $this->app->forgetInstance(ContributionEngine::class);

        $agent = $this->createScheduledAgent();
        $evaluation = app(ContributionEngine::class)->evaluate($agent, Carbon::parse('2026-07-07'));

        $byId = [];
        foreach ($evaluation->signals as $signal) {
            $byId[$signal->id->value] = $signal;
        }

        $this->assertTrue($byId[ContributionSignalId::Sales->value]->reserved);
        $this->assertFalse($byId[ContributionSignalId::Sales->value]->available);
        $this->assertTrue($byId[ContributionSignalId::ManualAdjustment->value]->reserved);
        $this->assertTrue($byId[ContributionSignalId::ActiveDuration->value]->available);
        $this->assertTrue($byId[ContributionSignalId::Email->value]->available);
        $this->assertFalse($byId[ContributionSignalId::Email->value]->reserved);
    }

    public function test_evaluation_includes_explainability_payload(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 18:30:00', 'Asia/Kolkata'));
        config(['workforce_contribution.enabled' => true]);
        $this->app->forgetInstance(ContributionEngine::class);

        $agent = $this->createScheduledAgent();
        $this->seedSession($agent, activeSeconds: 2000, casesHandled: 2);

        $evaluation = app(ContributionEngine::class)->evaluate($agent, Carbon::parse('2026-07-07'));
        $payload = $evaluation->explanationPayload();

        $this->assertNotEmpty($payload);
        $active = collect($payload)->firstWhere('signal', ContributionSignalId::ActiveDuration->value);
        $this->assertNotNull($active);
        $this->assertSame(2000, $active['observed_value']);
        $this->assertSame(1800, $active['normal_threshold']);
        $this->assertTrue($active['qualified']);
        $this->assertSame('normal', $active['level']);
        $this->assertNotEmpty($active['reason']);
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
        int $activeSeconds,
        int $casesHandled,
        string $loginTime = '09:00:00',
    ): void {
        $loginAt = Carbon::parse("2026-07-07 {$loginTime}", 'Asia/Kolkata');
        $logoutAt = Carbon::parse('2026-07-07 18:00:00', 'Asia/Kolkata');

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-07',
            'login_at' => $loginAt,
            'logout_at' => $logoutAt,
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'session_duration_seconds' => (int) $loginAt->diffInSeconds($logoutAt),
            'active_duration_seconds' => $activeSeconds,
            'cases_handled_count' => $casesHandled,
            'communication_events_count' => 0,
            'resolution_events_count' => 0,
            'on_time_login' => true,
            'is_attributable' => true,
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

        return $agent->fresh(['workSchedule', 'roles']);
    }
}

final class ContributionRecordingPublisher implements WorkforceEventPublisher
{
    /** @var list<WorkforceEvent> */
    public array $events = [];

    public function publish(WorkforceEvent $event): void
    {
        $this->events[] = $event;
    }
}
