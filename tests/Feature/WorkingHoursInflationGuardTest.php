<?php

namespace Tests\Feature;

use App\Enums\WorkSessionEndReason;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use App\Models\WorkSession;
use App\Services\Operations\AttendanceRegisterService;
use App\Services\Operations\PresenceEngineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WorkingHoursInflationGuardTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceRegisterService $register;

    private PresenceEngineService $presence;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->register = app(AttendanceRegisterService::class);
        $this->presence = app(PresenceEngineService::class);

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

    public function test_mid_day_reconcile_does_not_tick_open_session_to_end_of_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 09:56:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $this->presence->startSession($agent);

        Carbon::setTestNow(Carbon::parse('2026-07-28 15:00:00', 'Asia/Kolkata'));
        $this->presence->recordActivity($agent, createIfMissing: true);

        $this->register->reconcileRange(
            startDate: Carbon::parse('2026-07-28', 'Asia/Kolkata'),
            endDate: Carbon::parse('2026-07-28', 'Asia/Kolkata'),
            users: collect([$agent]),
        );

        $session = WorkSession::query()->where('user_id', $agent->id)->first();
        $this->assertNotNull($session);
        $this->assertTrue($session->isOpen());
        $this->assertSame(
            '2026-07-28 15:00:00',
            $session->last_tick_at?->timezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
        );
        $this->assertLessThanOrEqual(
            (int) $session->login_at->diffInSeconds(now()),
            (int) $session->active_duration_seconds,
        );
    }

    public function test_auto_logout_after_mid_day_reconcile_keeps_active_within_wall_clock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 09:56:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $this->presence->startSession($agent);

        Carbon::setTestNow(Carbon::parse('2026-07-28 15:00:00', 'Asia/Kolkata'));
        $this->presence->recordActivity($agent, createIfMissing: true);

        $this->register->reconcileRange(
            startDate: Carbon::parse('2026-07-28', 'Asia/Kolkata'),
            endDate: Carbon::parse('2026-07-28', 'Asia/Kolkata'),
            users: collect([$agent]),
        );

        Carbon::setTestNow(Carbon::parse('2026-07-28 18:26:00', 'Asia/Kolkata'));
        $closed = $this->presence->closeSession($agent, WorkSessionEndReason::AwayTimeout);

        $this->assertNotNull($closed);
        $this->assertNotNull($closed->logout_at);
        $this->assertSame(
            (int) $closed->login_at->diffInSeconds($closed->logout_at),
            (int) $closed->session_duration_seconds,
        );
        $this->assertActiveWithinSessionDuration($closed);
    }

    public function test_multiple_mid_day_reconciles_do_not_inflate_active_duration(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 09:56:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $this->presence->startSession($agent);

        Carbon::setTestNow(Carbon::parse('2026-07-28 12:00:00', 'Asia/Kolkata'));
        $this->presence->recordActivity($agent, createIfMissing: true);

        foreach (['13:00:00', '14:00:00', '15:00:00'] as $clock) {
            Carbon::setTestNow(Carbon::parse("2026-07-28 {$clock}", 'Asia/Kolkata'));
            $this->presence->recordActivity($agent, createIfMissing: true);

            $this->register->reconcileRange(
                startDate: Carbon::parse('2026-07-28', 'Asia/Kolkata'),
                endDate: Carbon::parse('2026-07-28', 'Asia/Kolkata'),
                users: collect([$agent]),
            );
        }

        $session = WorkSession::query()->where('user_id', $agent->id)->first();
        $this->assertNotNull($session);

        $elapsed = (int) $session->login_at->diffInSeconds(now());
        $this->assertLessThanOrEqual($elapsed, (int) $session->active_duration_seconds);
        $this->assertSame(
            '2026-07-28 15:00:00',
            $session->last_tick_at?->timezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
        );

        $day = WorkforceAttendanceDay::query()
            ->where('user_id', $agent->id)
            ->whereDate('work_date', '2026-07-28')
            ->first();

        $this->assertNotNull($day);
        $this->assertLessThanOrEqual($elapsed, (int) $day->active_duration_seconds);
    }

    public function test_historical_day_reconcile_still_uses_end_of_day_reference(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 15:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-27',
            'login_at' => Carbon::parse('2026-07-27 09:00:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse('2026-07-27 18:00:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'session_duration_seconds' => 32_400,
            'active_duration_seconds' => 28_800,
            'on_time_login' => true,
            'last_activity_at' => Carbon::parse('2026-07-27 17:50:00', 'Asia/Kolkata'),
            'last_tick_at' => Carbon::parse('2026-07-27 18:00:00', 'Asia/Kolkata'),
        ]);

        $this->register->reconcileRange(
            startDate: Carbon::parse('2026-07-27', 'Asia/Kolkata'),
            endDate: Carbon::parse('2026-07-27', 'Asia/Kolkata'),
            users: collect([$agent]),
        );

        $session = WorkSession::query()
            ->where('user_id', $agent->id)
            ->whereDate('work_date', '2026-07-27')
            ->first();

        $this->assertNotNull($session);
        $this->assertActiveWithinSessionDuration($session);

        $day = WorkforceAttendanceDay::query()
            ->where('user_id', $agent->id)
            ->whereDate('work_date', '2026-07-27')
            ->first();

        $this->assertNotNull($day);
        $this->assertSame(28_800, (int) $day->active_duration_seconds);
        $this->assertSame(32_400, (int) $day->session_duration_seconds);
        $this->assertLessThanOrEqual(
            (int) $day->session_duration_seconds,
            (int) $day->active_duration_seconds,
        );
    }

    public function test_close_clamps_active_when_last_tick_was_incorrectly_advanced_to_end_of_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 09:56:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $session = $this->presence->startSession($agent);
        $this->assertNotNull($session);

        Carbon::setTestNow(Carbon::parse('2026-07-28 18:10:00', 'Asia/Kolkata'));
        $this->presence->recordActivity($agent, createIfMissing: true);

        // Simulate the historical bug: open session flushed to endOfDay.
        $session->refresh();
        $endOfDay = Carbon::parse('2026-07-28 23:59:59', 'Asia/Kolkata');
        $inflatedActive = (int) $session->login_at->diffInSeconds($endOfDay);
        $session->forceFill([
            'last_tick_at' => $endOfDay,
            'active_duration_seconds' => $inflatedActive,
        ])->save();

        Carbon::setTestNow(Carbon::parse('2026-07-28 18:26:00', 'Asia/Kolkata'));
        $closed = $this->presence->closeSession($agent, WorkSessionEndReason::AwayTimeout);

        $this->assertNotNull($closed);
        $this->assertActiveWithinSessionDuration($closed);
        $this->assertLessThan($inflatedActive, (int) $closed->active_duration_seconds);
        $this->assertSame(
            (int) $closed->login_at->diffInSeconds($closed->logout_at),
            (int) $closed->session_duration_seconds,
        );
    }

    public function test_mid_day_reconcile_rewinds_incorrect_end_of_day_tick_on_open_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 09:56:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $session = $this->presence->startSession($agent);
        $this->assertNotNull($session);

        Carbon::setTestNow(Carbon::parse('2026-07-28 15:00:00', 'Asia/Kolkata'));
        $this->presence->recordActivity($agent, createIfMissing: true);

        $session->refresh();
        $endOfDay = Carbon::parse('2026-07-28 23:59:59', 'Asia/Kolkata');
        $session->forceFill([
            'last_tick_at' => $endOfDay,
            'active_duration_seconds' => (int) $session->login_at->diffInSeconds($endOfDay),
        ])->save();

        $this->register->reconcileRange(
            startDate: Carbon::parse('2026-07-28', 'Asia/Kolkata'),
            endDate: Carbon::parse('2026-07-28', 'Asia/Kolkata'),
            users: collect([$agent]),
        );

        $session->refresh();
        $elapsed = (int) $session->login_at->diffInSeconds(now());

        $this->assertSame(
            '2026-07-28 15:00:00',
            $session->last_tick_at?->timezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
        );
        $this->assertLessThanOrEqual($elapsed, (int) $session->active_duration_seconds);
    }

    private function assertActiveWithinSessionDuration(WorkSession $session): void
    {
        // PHPUnit: assertLessThanOrEqual($expected, $actual) ⇒ $actual <= $expected
        $this->assertLessThanOrEqual(
            (int) $session->session_duration_seconds,
            (int) $session->active_duration_seconds,
            'active_duration_seconds must not exceed session_duration_seconds',
        );
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
