<?php

namespace Tests\Feature\Platform;

use App\Enums\PlatformHealthStatus;
use App\Models\User;
use App\Services\Platform\PlatformDashboardService;
use App\Services\Platform\PlatformHealthCache;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PlatformDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function createSuperadmin(): User
    {
        $user = User::factory()->create([
            'name' => 'Platform Superadmin',
            'email' => 'platform-superadmin@test.com',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }

    private function createOperationsAdmin(): User
    {
        $user = User::factory()->create([
            'name' => 'Platform Ops Admin',
            'email' => 'platform-ops-admin@test.com',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        return $user;
    }

    private function createAdmin(): User
    {
        $user = User::factory()->create([
            'name' => 'Platform Admin',
            'email' => 'platform-admin@test.com',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createAgent(): User
    {
        $user = User::factory()->create([
            'name' => 'Platform Agent',
            'email' => 'platform-agent@test.com',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    public function test_agent_cannot_access_platform_dashboard(): void
    {
        $this->actingAs($this->createAgent())
            ->get(route('admin.platform.index'))
            ->assertForbidden();
    }

    public function test_admin_cannot_access_platform_dashboard(): void
    {
        $this->actingAs($this->createAdmin())
            ->get(route('admin.platform.index'))
            ->assertForbidden();
    }

    public function test_superadmin_can_view_platform_dashboard(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 11:00:00', 'Asia/Kolkata'));
        PlatformHealthCache::recordSchedulerHeartbeat(now());
        PlatformHealthCache::recordPresenceTimeoutRun(0, 0, now());

        $this->actingAs($this->createSuperadmin())
            ->get(route('admin.platform.index'))
            ->assertOk()
            ->assertSee('Command Center', false)
            ->assertSee('Platform Health', false)
            ->assertSee('Scheduler', false)
            ->assertSee('Presence Engine', false);
    }

    public function test_operations_admin_can_view_platform_dashboard(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 11:00:00', 'Asia/Kolkata'));
        PlatformHealthCache::recordSchedulerHeartbeat(now());
        PlatformHealthCache::recordPresenceTimeoutRun(0, 0, now());

        $this->actingAs($this->createOperationsAdmin())
            ->get(route('admin.platform.index'))
            ->assertOk()
            ->assertSee('Platform Health', false);
    }

    public function test_card_refresh_endpoint_returns_html_payload(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 11:00:00', 'Asia/Kolkata'));
        PlatformHealthCache::recordSchedulerHeartbeat(now());
        PlatformHealthCache::recordPresenceTimeoutRun(0, 0, now());

        $response = $this->actingAs($this->createSuperadmin())
            ->getJson(route('admin.platform.cards.show', ['card' => 'platform_health']));

        $response->assertOk()
            ->assertJsonPath('key', 'platform_health')
            ->assertJsonStructure([
                'key',
                'status',
                'status_label',
                'generated_at',
                'html',
                'payload',
            ]);

        $this->assertStringContainsString('data-platform-card', (string) $response->json('html'));
        $this->assertStringContainsString('Platform Health', (string) $response->json('html'));
    }

    public function test_unknown_card_refresh_returns_not_found(): void
    {
        $this->actingAs($this->createSuperadmin())
            ->getJson(route('admin.platform.cards.show', ['card' => 'missing_card']))
            ->assertNotFound();
    }

    public function test_dashboard_service_builds_platform_health_section(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 11:00:00', 'Asia/Kolkata'));
        PlatformHealthCache::recordSchedulerHeartbeat(now());
        PlatformHealthCache::recordPresenceTimeoutRun(0, 0, now());

        $dashboard = app(PlatformDashboardService::class)->build($this->createSuperadmin());
        $sectionKeys = collect($dashboard->sections)->pluck('key')->all();

        $this->assertContains('executive', $sectionKeys);
        $this->assertContains('platform_health', $sectionKeys);

        $healthSection = collect($dashboard->sections)->firstWhere('key', 'platform_health');
        $this->assertNotNull($healthSection);
        $this->assertSame('platform_health', $healthSection['cards'][0]->key);
        $this->assertContains(
            $healthSection['cards'][0]->status,
            PlatformHealthStatus::cases(),
        );
    }

    public function test_scheduler_health_is_critical_without_heartbeat(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 11:00:00', 'Asia/Kolkata'));
        PlatformHealthCache::recordPresenceTimeoutRun(0, 0, now());

        $payload = app(PlatformDashboardService::class)
            ->cardPayload($this->createSuperadmin(), 'platform_health');

        $scheduler = collect($payload->meta['components'] ?? [])
            ->firstWhere('key', 'scheduler');

        $this->assertSame(PlatformHealthStatus::Critical->value, $scheduler['status'] ?? null);
    }

    public function test_presence_timeout_command_records_health_cache(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 11:00:00', 'Asia/Kolkata'));

        $this->artisan('presence:process-timeouts')->assertSuccessful();

        $this->assertNotNull(PlatformHealthCache::presenceLastTimeoutRunAt());
        $this->assertSame(0, PlatformHealthCache::presenceStaleSessionCount());
    }
}
