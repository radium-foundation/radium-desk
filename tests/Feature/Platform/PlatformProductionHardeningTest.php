<?php

namespace Tests\Feature\Platform;

use App\Services\Platform\PlatformCachePolicy;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
use App\Services\Platform\Warmers\PlatformWarmingActor;
use App\Services\Platform\Warmers\PlatformSnapshotWarmingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PlatformProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    public function test_cache_ttl_policy_matches_priority_bands(): void
    {
        $this->assertSame(120, PlatformCachePolicy::ttlForZone('critical_alerts'));
        $this->assertSame(120, PlatformCachePolicy::ttlForZone('executive_snapshot'));
        $this->assertSame(120, PlatformCachePolicy::ttlForZone('platform_health'));
        $this->assertSame(120, PlatformCachePolicy::ttlForZone('integration_health'));
        $this->assertSame(300, PlatformCachePolicy::ttlForZone('performance'));
        $this->assertSame(300, PlatformCachePolicy::ttlForZone('automation'));
        $this->assertSame(300, PlatformCachePolicy::ttlForZone('communications'));
        $this->assertSame(300, PlatformCachePolicy::ttlForZone('email_operations'));
        $this->assertSame(300, PlatformCachePolicy::ttlForZone('finance_overview'));
        $this->assertSame(300, PlatformCachePolicy::ttlForZone('operations_overview'));
        $this->assertSame(300, PlatformCachePolicy::ttlForZone('tools'));
        $this->assertSame(
            [PlatformCachePolicy::KEY_EMAIL_OPERATIONS_OVERVIEW],
            PlatformCachePolicy::relatedOverviewKeys('email_operations'),
        );
    }

    public function test_synthetic_warming_actor_warms_without_users(): void
    {
        $actor = PlatformWarmingActor::synthetic();
        $this->assertTrue(PlatformWarmingActor::isSynthetic($actor));

        $result = app(PlatformSnapshotWarmingService::class)->warmAll($actor);

        $this->assertTrue($result['synthetic_actor']);
        $this->assertContains('platform_health', $result['warmed']);
        $this->assertContains('executive_snapshot', $result['warmed']);
        $this->assertNotNull(app(PlatformZoneSnapshotStore::class)->get('platform_health'));
        $this->assertNotNull(app(PlatformZoneSnapshotStore::class)->get('executive_snapshot'));
    }

    public function test_operations_critical_alerts_do_not_fetch_cashfree_or_radiumbox_widgets(): void
    {
        $user = \App\Models\User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $cashfree = \Mockery::mock(\App\Services\Operations\OperationsCashfreeHealthService::class);
        $cashfree->shouldReceive('widget')->never();
        $this->app->instance(\App\Services\Operations\OperationsCashfreeHealthService::class, $cashfree);

        $radium = \Mockery::mock(\App\Services\Operations\OperationsRadiumBoxHealthService::class);
        $radium->shouldReceive('widget')->never();
        $this->app->instance(\App\Services\Operations\OperationsRadiumBoxHealthService::class, $radium);

        $this->actingAs($user)
            ->getJson(route('admin.operations.live', ['groups' => 'critical']))
            ->assertOk()
            ->assertSee('Open Platform Dashboard', false)
            ->assertSee('Platform Alerts', false);
    }
}
