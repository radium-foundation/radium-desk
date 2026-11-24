<?php

namespace Tests\Feature\Session;

use App\Models\User;
use App\Models\WorkSession;
use App\Services\Operations\OperationsRoleService;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\TeamMemberActivityService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionLifecycleTest extends TestCase
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

    public function test_authenticated_session_status_probe_returns_success(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $response = $this->actingAs($agent)
            ->getJson(route('session.status'));

        $response->assertOk()
            ->assertJson(['authenticated' => true]);
    }

    public function test_unauthenticated_session_status_probe_returns_401(): void
    {
        $this->getJson(route('session.status'))
            ->assertUnauthorized();
    }

    public function test_session_probe_does_not_create_work_session(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)->getJson(route('session.status'))->assertOk();

        $this->assertSame(0, WorkSession::query()->where('user_id', $agent->id)->count());
    }

    public function test_session_probe_does_not_record_system_activity(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $activitySpy = $this->spy(TeamMemberActivityService::class);

        $this->actingAs($agent)->getJson(route('session.status'))->assertOk();

        $activitySpy->shouldNotHaveReceived('recordSystemActivity');
    }

    public function test_session_probe_does_not_update_session_last_activity_timestamp(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $sessionId = session()->getId();
        $original = DB::table('sessions')->where('id', $sessionId)->value('last_activity');

        Carbon::setTestNow(now()->addMinutes(3));

        $this->actingAs($user)->getJson(route('session.status'))->assertOk();

        $updated = DB::table('sessions')->where('id', $sessionId)->value('last_activity');

        $this->assertSame($original, $updated);
    }

    public function test_away_timeout_still_force_logs_out_on_probe(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 09:00:00', 'Asia/Kolkata'));

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $presence = app(PresenceEngineService::class);
        $session = $presence->startSession($agent);
        $this->assertNotNull($session);
        $session->update(['last_activity_at' => now()->subMinutes(20)]);

        $this->actingAs($agent)
            ->getJson(route('session.status'))
            ->assertUnauthorized();
    }

    public function test_super_admin_is_not_subject_to_presence_away_force_logout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 09:00:00', 'Asia/Kolkata'));

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $roleService = app(OperationsRoleService::class);
        $this->assertTrue($roleService->isTeamMember($superAdmin));
        $this->assertFalse($roleService->isAttendanceTracked($superAdmin));

        $this->actingAs($superAdmin)->getJson(route('session.status'))->assertOk();
    }

    public function test_authenticated_dashboard_html_has_required_cache_control(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }

    public function test_login_page_does_not_receive_authenticated_cache_control(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();

        $cacheControl = $response->headers->get('Cache-Control', '');

        $this->assertStringNotContainsString('no-store', strtolower($cacheControl));
    }

    public function test_session_status_json_does_not_receive_html_cache_control_directive(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $response = $this->actingAs($agent)->getJson(route('session.status'));

        $response->assertOk();

        $cacheControl = (string) $response->headers->get('Cache-Control', '');

        $this->assertStringNotContainsString('no-store, no-cache, must-revalidate, private', $cacheControl);
    }
}