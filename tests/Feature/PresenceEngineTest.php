<?php

namespace Tests\Feature;

use App\Enums\PresenceActivityType;
use App\Enums\PresenceStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\TeamMemberActivityService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PresenceEngineTest extends TestCase
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

    public function test_login_starts_work_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 08:58:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Avinash Jha');

        $this->post(route('login'), [
            'email' => $agent->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $session = WorkSession::query()->where('user_id', $agent->id)->first();

        $this->assertNotNull($session);
        $this->assertTrue($session->isOpen());
        $this->assertSame('2026-07-06', $session->work_date->toDateString());
        $this->assertSame('08:58', $session->login_at?->format('H:i'));
        $this->assertTrue($session->on_time_login);
    }

    public function test_login_creates_session_and_sets_available_from_offline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 09:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Offline Login Agent', TeamAvailabilityStatus::Offline);

        $this->post(route('login'), [
            'email' => $agent->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $agent->refresh();

        $this->assertNotNull(WorkSession::query()->where('user_id', $agent->id)->first());
        $this->assertSame(TeamAvailabilityStatus::Available, $agent->availability_status);
    }

    public function test_login_preserves_busy_availability(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 09:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Busy Login Agent', TeamAvailabilityStatus::Busy);

        $this->post(route('login'), [
            'email' => $agent->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $agent->refresh();

        $this->assertSame(TeamAvailabilityStatus::Busy, $agent->availability_status);
    }

    public function test_admin_login_creates_workforce_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 09:00:00', 'Asia/Kolkata'));

        $admin = User::factory()->create([
            'name' => 'Ops Admin',
            'password' => bcrypt('password'),
            'availability_status' => TeamAvailabilityStatus::Offline,
            'availability_updated_at' => now(),
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->post(route('login'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertNotNull(WorkSession::query()->where('user_id', $admin->id)->first());
    }

    public function test_superadmin_login_does_not_create_workforce_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 09:00:00', 'Asia/Kolkata'));

        $owner = User::factory()->create([
            'name' => 'Owner',
            'password' => bcrypt('password'),
            'availability_status' => TeamAvailabilityStatus::Offline,
            'availability_updated_at' => now(),
        ]);
        $owner->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->post(route('login'), [
            'email' => $owner->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertNull(WorkSession::query()->where('user_id', $owner->id)->first());
    }

    public function test_logout_closes_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 09:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Logout Agent');
        $presenceEngine = app(PresenceEngineService::class);
        $presenceEngine->startSession($agent);

        Carbon::setTestNow(Carbon::parse('2026-07-06 18:10:00', 'Asia/Kolkata'));

        $closed = $presenceEngine->closeSession($agent, WorkSessionEndReason::ManualLogout);

        $this->assertNotNull($closed?->logout_at);
        $this->assertSame(WorkSessionEndReason::ManualLogout, $closed?->ended_reason);
        $this->assertSame('18:10', $closed?->logout_at?->format('H:i'));

        $agent->refresh();
        $this->assertSame(TeamAvailabilityStatus::Offline, $agent->availability_status);
    }

    public function test_logout_route_closes_session_and_sets_offline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Route Logout Agent', TeamAvailabilityStatus::Offline);

        $this->post(route('login'), [
            'email' => $agent->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $agent->refresh();
        $this->assertSame(TeamAvailabilityStatus::Available, $agent->availability_status);

        $this->actingAs($agent)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $agent->refresh();

        $this->assertNull(app(PresenceEngineService::class)->openSessionFor($agent));
        $this->assertSame(TeamAvailabilityStatus::Offline, $agent->availability_status);
    }

    public function test_activity_under_five_minutes_is_active(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Active Agent');
        $session = app(PresenceEngineService::class)->startSession($agent);
        $session?->update(['last_activity_at' => now()->subMinutes(4)]);

        $agent->refresh();

        $this->assertSame(
            PresenceStatus::Active,
            app(PresenceEngineService::class)->presenceStatus($agent),
        );
    }

    public function test_inactivity_between_five_and_fifteen_minutes_is_idle(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Idle Agent');
        $session = app(PresenceEngineService::class)->startSession($agent);
        $session?->update(['last_activity_at' => now()->subMinutes(8)]);

        $agent->refresh();

        $this->assertSame(
            PresenceStatus::Idle,
            app(PresenceEngineService::class)->presenceStatus($agent),
        );
    }

    public function test_fifteen_minutes_inactivity_triggers_away_logout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Away Agent');
        $session = app(PresenceEngineService::class)->startSession($agent);
        $session?->update(['last_activity_at' => now()->subMinutes(16)]);

        $agent->refresh();

        $presenceEngine = app(PresenceEngineService::class);

        $this->assertSame(PresenceStatus::Away, $presenceEngine->presenceStatus($agent));
        $this->assertTrue($presenceEngine->shouldForceLogout($agent));

        $processed = $presenceEngine->processTimedOutSessions();
        $this->assertSame(1, $processed);

        $session->refresh();
        $this->assertSame(WorkSessionEndReason::AwayTimeout, $session->ended_reason);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $agent->id)->count());

        $agent->refresh();
        $this->assertSame(TeamAvailabilityStatus::Offline, $agent->availability_status);
    }

    public function test_lunch_time_is_excluded_from_idle(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 13:35:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Lunch Agent');
        $presenceEngine = app(PresenceEngineService::class);
        $session = $presenceEngine->startSession($agent);

        $session?->update([
            'last_activity_at' => Carbon::parse('2026-07-06 13:30:00', 'Asia/Kolkata'),
            'last_tick_at' => Carbon::parse('2026-07-06 13:30:00', 'Asia/Kolkata'),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-06 13:40:00', 'Asia/Kolkata'));

        $presenceEngine->tickSession($session->fresh(), now(), hasActivity: false);
        $session->refresh();

        $this->assertGreaterThan(0, $session->lunch_duration_seconds);
        $this->assertSame(0, $session->idle_duration_seconds);
    }

    public function test_break_allowance_is_respected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Break Agent');
        $presenceEngine = app(PresenceEngineService::class);
        $session = $presenceEngine->startSession($agent);

        $this->assertSame(1200, $session?->break_allowance_seconds);

        $session?->update([
            'last_activity_at' => Carbon::parse('2026-07-06 09:54:00', 'Asia/Kolkata'),
            'last_tick_at' => Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-06 10:10:00', 'Asia/Kolkata'));
        $presenceEngine->tickSession($session->fresh(), now(), hasActivity: false);
        $session->refresh();

        $this->assertSame(600, $session->break_duration_seconds);
        $this->assertSame(0, $session->extra_idle_duration_seconds);

        $session->update(['last_tick_at' => now()]);
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:30:00', 'Asia/Kolkata'));
        $presenceEngine->tickSession($session->fresh(), now(), hasActivity: false);
        $session->refresh();

        $this->assertSame(1200, $session->break_duration_seconds);
        $this->assertSame(600, $session->extra_idle_duration_seconds);
    }

    public function test_overtime_is_calculated_on_logout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 09:02:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Overtime Agent');
        $presenceEngine = app(PresenceEngineService::class);
        $presenceEngine->startSession($agent);

        Carbon::setTestNow(Carbon::parse('2026-07-06 18:10:00', 'Asia/Kolkata'));
        $session = $presenceEngine->closeSession($agent, WorkSessionEndReason::ManualLogout);

        $this->assertSame(600, $session?->overtime_seconds);
        $this->assertSame('10m', $presenceEngine->formatDuration((int) $session?->overtime_seconds));
    }

    public function test_work_activity_updates_presence(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Work Activity Agent');
        app(PresenceEngineService::class)->startSession($agent);

        $session = WorkSession::query()->where('user_id', $agent->id)->first();
        $session?->update([
            'last_activity_at' => now()->subMinutes(10),
            'last_tick_at' => now()->subMinutes(10),
        ]);

        app(TeamMemberActivityService::class)->recordCustomerCommunication($agent);

        $agent->refresh();
        $session->refresh();

        $this->assertSame(PresenceStatus::Active, app(PresenceEngineService::class)->presenceStatus($agent));
        $this->assertSame(1, $session->communication_events_count);
    }

    public function test_heartbeat_records_presence_activity(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Heartbeat Agent');
        app(PresenceEngineService::class)->startSession($agent);

        $this->actingAs($agent)
            ->postJson(route('presence.heartbeat'))
            ->assertOk()
            ->assertJsonPath('presence.status', PresenceStatus::Active->value);

        $this->assertNotNull(WorkSession::query()->where('user_id', $agent->id)->value('last_activity_at'));
    }

    private function createAgentWithSchedule(
        string $name,
        TeamAvailabilityStatus $status = TeamAvailabilityStatus::Available,
    ): User {
        $user = User::factory()->create([
            'name' => $name,
            'password' => bcrypt('password'),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $user->update([
            'availability_status' => $status,
            'availability_updated_at' => now(),
        ]);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        return $user->fresh(['workSchedule']);
    }
}
