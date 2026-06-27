<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DashboardService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardOnlineUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_dashboard_shows_online_users_kpi(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ravi',
            'last_name' => 'Sharma',
            'name' => 'Ravi Sharma',
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->seedActiveSession($user);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Online Users')
            ->assertSee('<span class="dashboard-kpi-value-number">1</span>', false);
    }

    public function test_online_users_counts_distinct_users_with_recent_sessions(): void
    {
        $onlineUser = User::factory()->create([
            'first_name' => 'Avinash',
            'last_name' => 'Kumar',
            'name' => 'Avinash Kumar',
        ]);
        $onlineUser->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $offlineUser = User::factory()->create([
            'first_name' => 'Offline',
            'last_name' => 'User',
            'name' => 'Offline User',
        ]);
        $offlineUser->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->seedActiveSession($onlineUser);
        $this->seedActiveSession($onlineUser, sessionId: 'second-tab-session');
        $this->seedActiveSession($offlineUser, lastActivity: now()->subMinutes(10)->getTimestamp());

        $stats = app(DashboardService::class)->statsFor($onlineUser);

        $this->assertSame(1, $stats['online_count']);
        $this->assertCount(1, $stats['online_users']);
        $this->assertSame($onlineUser->id, $stats['online_users']->first()->id);
    }

    public function test_online_users_excludes_inactive_accounts(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $inactiveUser = User::factory()->create(['is_active' => false]);
        $inactiveUser->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->seedActiveSession($inactiveUser);

        $stats = app(DashboardService::class)->statsFor($viewer);

        $this->assertSame(0, $stats['online_count']);
        $this->assertTrue($stats['online_users']->isEmpty());
    }

    public function test_dashboard_live_returns_online_users_payload(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Shipra',
            'last_name' => 'Verma',
            'name' => 'Shipra Verma',
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $otherUser = User::factory()->create([
            'first_name' => 'Gaurav',
            'last_name' => 'Singh',
            'name' => 'Gaurav Singh',
        ]);
        $otherUser->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->seedActiveSession($user);
        $this->seedActiveSession($otherUser);

        $response = $this->actingAs($user)
            ->getJson(route('dashboard.live', ['filter' => 'pending_admin']));

        $response->assertOk();
        $response->assertJsonPath('online_count', 2);
        $response->assertJsonFragment(['name' => 'Gaurav S']);
        $response->assertJsonFragment(['name' => 'Shipra V']);

        $names = collect($response->json('online_users'))->pluck('name')->all();
        $sortedNames = $names;
        sort($sortedNames, SORT_NATURAL | SORT_FLAG_CASE);

        $this->assertSame($sortedNames, $names);
        $this->assertStringContainsString('Online Users', $response->json('kpi_strip_html'));
        $this->assertStringContainsString(
            '<span class="dashboard-kpi-value-number">2</span>',
            $response->json('kpi_strip_html'),
        );
    }

    public function test_dashboard_live_shows_zero_online_when_no_recent_sessions(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $response = $this->actingAs($user)
            ->getJson(route('dashboard.live', ['filter' => 'pending_admin']));

        $response->assertOk();
        $response->assertJsonPath('online_count', 0);
        $response->assertJsonPath('online_users', []);
        $this->assertStringContainsString(
            '<span class="dashboard-kpi-value-number">0</span>',
            $response->json('kpi_strip_html'),
        );
        $this->assertStringContainsString('No active users', $response->json('kpi_strip_html'));
    }

    public function test_online_user_display_name_uses_first_name_and_last_initial(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ravi',
            'last_name' => 'Sharma',
            'name' => 'Ravi Sharma',
        ]);

        $this->assertSame('Ravi S', app(DashboardService::class)->onlineUserDisplayName($user));
    }

    private function seedActiveSession(
        User $user,
        ?string $sessionId = null,
        ?int $lastActivity = null,
    ): void {
        DB::table('sessions')->insert([
            'id' => $sessionId ?? Str::random(40),
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => $lastActivity ?? now()->getTimestamp(),
        ]);
    }
}
