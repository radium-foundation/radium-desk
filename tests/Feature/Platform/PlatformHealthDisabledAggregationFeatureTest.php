<?php

namespace Tests\Feature\Platform;

use App\Enums\PlatformHealthStatus;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Platform\Health\PlatformHealthSnapshotService;
use App\Services\Platform\PlatformHealthCache;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PlatformHealthDisabledAggregationFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
        PlatformHealthCache::clearDurableForTests();
        Carbon::setTestNow(Carbon::parse('2026-08-04 23:00:00', 'Asia/Kolkata'));
        PlatformHealthCache::recordSchedulerHeartbeat(now()->subMinute());
        PlatformHealthCache::recordPresenceTimeoutRun(0, 0, now()->subMinute());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PlatformHealthCache::clearDurableForTests();

        parent::tearDown();
    }

    public function test_probe_overall_healthy_when_queue_and_automation_disabled(): void
    {
        config(['infrastructure.queue_worker_mode' => 'disabled']);
        SystemSetting::query()->updateOrCreate(
            ['key' => 'automation.scheduler.enabled'],
            ['value' => '0'],
        );
        app(SystemSettingsService::class)->forget('automation.scheduler.enabled');

        $snapshot = app(PlatformHealthSnapshotService::class)->probe();

        $this->assertSame(PlatformHealthStatus::Disabled, $snapshot->component('queue')?->status);
        $this->assertSame(PlatformHealthStatus::Disabled, $snapshot->component('automation')?->status);
        $this->assertSame(PlatformHealthStatus::Healthy, $snapshot->component('scheduler')?->status);
        $this->assertSame(PlatformHealthStatus::Healthy, $snapshot->status);
    }

    public function test_zone_refresh_persists_healthy_overall_with_disabled_components(): void
    {
        config(['infrastructure.queue_worker_mode' => 'disabled']);
        SystemSetting::query()->updateOrCreate(
            ['key' => 'automation.scheduler.enabled'],
            ['value' => '0'],
        );
        app(SystemSettingsService::class)->forget('automation.scheduler.enabled');

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->actingAs($user)
            ->getJson(route('admin.platform.zones.show', ['zone' => 'platform_health']))
            ->assertOk()
            ->assertJsonPath('status', PlatformHealthStatus::Healthy->value);

        $snapshot = app(PlatformHealthSnapshotService::class)->current();
        $this->assertNotNull($snapshot);
        $this->assertSame(PlatformHealthStatus::Healthy, $snapshot->status);
        $this->assertSame(PlatformHealthStatus::Disabled, $snapshot->component('queue')?->status);
        $this->assertSame(PlatformHealthStatus::Disabled, $snapshot->component('automation')?->status);
    }
}
