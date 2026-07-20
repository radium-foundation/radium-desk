<?php

namespace Tests\Feature\Platform;

use App\Enums\PlatformHealthStatus;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Platform\Cards\PlatformHealthCardProvider;
use App\Services\Platform\PlatformHealthCache;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PlatformHealthCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-07-20 11:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function viewer(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }

    public function test_platform_health_card_aggregates_worst_status(): void
    {
        PlatformHealthCache::recordSchedulerHeartbeat(now()->subMinutes(15));
        PlatformHealthCache::recordPresenceTimeoutRun(0, 0, now());

        $payload = app(PlatformHealthCardProvider::class)->payload($this->viewer());

        $this->assertSame('platform_health', $payload->key);
        $this->assertSame(PlatformHealthStatus::Critical, $payload->status);
        $this->assertSame('admin.platform.cards.platform-health', $payload->bodyPartial);
        $this->assertNotEmpty($payload->meta['components'] ?? []);
    }

    public function test_presence_provider_flags_stale_sessions_as_critical(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => now()->toDateString(),
            'login_at' => now()->subHours(2),
            'last_activity_at' => now()->subMinutes(45),
            'last_tick_at' => now()->subMinutes(45),
        ]);

        PlatformHealthCache::recordSchedulerHeartbeat(now());
        PlatformHealthCache::recordPresenceTimeoutRun(0, 0, now());

        $payload = app(PlatformHealthCardProvider::class)->payload($this->viewer());
        $presence = collect($payload->meta['components'] ?? [])->firstWhere('key', 'presence');

        $this->assertSame(PlatformHealthStatus::Critical->value, $presence['status'] ?? null);
        $this->assertSame(1, $presence['metrics']['stale_sessions'] ?? null);
        $this->assertSame(PlatformHealthStatus::Critical, $payload->status);
    }

    public function test_healthy_heartbeats_produce_non_critical_presence_and_scheduler(): void
    {
        PlatformHealthCache::recordSchedulerHeartbeat(now()->subMinute());
        PlatformHealthCache::recordPresenceTimeoutRun(0, 0, now()->subMinute());

        $payload = app(PlatformHealthCardProvider::class)->payload($this->viewer());
        $components = collect($payload->meta['components'] ?? []);

        $this->assertSame(
            PlatformHealthStatus::Healthy->value,
            $components->firstWhere('key', 'scheduler')['status'] ?? null,
        );
        $this->assertSame(
            PlatformHealthStatus::Healthy->value,
            $components->firstWhere('key', 'presence')['status'] ?? null,
        );
    }
}
