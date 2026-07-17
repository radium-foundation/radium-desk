<?php

namespace Tests\Feature;

use App\Enums\AttendanceDayStatus;
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

class AttendanceRegisterTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceRegisterService $registerService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->registerService = app(AttendanceRegisterService::class);

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

    public function test_refresh_day_persists_one_row_per_user_per_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 18:30:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-07',
            'login_at' => Carbon::parse('2026-07-07 09:00:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse('2026-07-07 18:00:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'session_duration_seconds' => 32400,
            'on_time_login' => true,
        ]);

        $first = $this->registerService->refreshDay($agent, Carbon::parse('2026-07-07'));
        $second = $this->registerService->refreshDay($agent, Carbon::parse('2026-07-07'));

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, WorkforceAttendanceDay::query()->where('user_id', $agent->id)->count());
        $this->assertSame(AttendanceDayStatus::Completed, $first->status);
    }

    public function test_refresh_day_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 18:30:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-07',
            'login_at' => Carbon::parse('2026-07-07 09:20:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse('2026-07-07 18:00:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'session_duration_seconds' => 31200,
            'on_time_login' => false,
        ]);

        $this->registerService->refreshDay($agent, Carbon::parse('2026-07-07'));
        $this->registerService->refreshDay($agent, Carbon::parse('2026-07-07'));

        $row = WorkforceAttendanceDay::query()->where('user_id', $agent->id)->first();

        $this->assertNotNull($row);
        $this->assertSame(AttendanceDayStatus::Late, $row->status);
        $this->assertSame(1, $row->session_count);
        $this->assertSame(1, $row->manual_logout_count);
    }

    public function test_presence_session_start_and_close_refresh_register(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 09:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $presence = app(PresenceEngineService::class);

        $presence->startSession($agent);

        $activeRow = WorkforceAttendanceDay::query()->where('user_id', $agent->id)->first();
        $this->assertNotNull($activeRow);
        $this->assertSame(AttendanceDayStatus::Active, $activeRow->status);
        $this->assertNull($activeRow->finalized_at);

        Carbon::setTestNow(Carbon::parse('2026-07-07 18:00:00', 'Asia/Kolkata'));
        $presence->closeSession($agent, WorkSessionEndReason::ManualLogout);

        $completedRow = WorkforceAttendanceDay::query()->where('user_id', $agent->id)->first();
        $this->assertNotNull($completedRow);
        $this->assertSame(AttendanceDayStatus::Completed, $completedRow->status);
        $this->assertNotNull($completedRow->finalized_at);
        $this->assertSame(1, WorkforceAttendanceDay::query()->where('user_id', $agent->id)->count());
    }

    public function test_reconcile_command_rebuilds_register_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 12:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent(weeklyOffDays: [Carbon::MONDAY]);

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-06',
            'login_at' => Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse('2026-07-06 12:00:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'session_duration_seconds' => 7200,
            'on_time_login' => null,
        ]);

        $this->artisan('attendance:reconcile-days', [
            '--from' => '2026-07-06',
            '--to' => '2026-07-06',
            '--user' => (string) $agent->id,
        ])->assertSuccessful();

        $row = WorkforceAttendanceDay::query()
            ->where('user_id', $agent->id)
            ->whereDate('work_date', '2026-07-06')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(AttendanceDayStatus::Extra, $row->status);
    }

    public function test_existing_presence_flows_remain_functional(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 09:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $presence = app(PresenceEngineService::class);

        $session = $presence->startSession($agent);

        $this->assertNotNull($session);
        $this->assertTrue($session->isOpen());
        $this->assertSame(1, WorkSession::query()->where('user_id', $agent->id)->count());
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
