<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Dashboard\ActiveIncidentSnapshotPayload;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\Dashboard\OperatorDashboardCache;
use App\Services\DashboardService;
use App\Services\IncidentReferenceService;
use App\Services\ServiceCaseStatusService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperatorDashboardCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        Cache::flush();
        app(DashboardSnapshotStore::class)->forget();
    }

    public function test_active_incident_snapshot_is_reused_across_requests_within_ttl(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createOpenIncident($admin);

        app(DashboardSnapshotStore::class)->get();
        $this->assertTrue(Cache::has(OperatorDashboardCache::SNAPSHOT_CACHE_KEY));

        // Simulate a new HTTP request: drop request-scoped store, keep Cache.
        app()->forgetInstance(DashboardSnapshotStore::class);
        app(\App\Services\Operations\OperationsQueueClassifier::class)->forgetClassifications();

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(DashboardSnapshotStore::class)->get();

        $incidentQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), ' from "incidents"')
                || str_contains(strtolower($query['query']), ' from `incidents`'))
            ->count();

        DB::disableQueryLog();

        $this->assertSame(
            0,
            $incidentQueries,
            'Cross-request snapshot cache should avoid a second incidents hydrate within TTL.',
        );
        $this->assertTrue(Cache::has(OperatorDashboardCache::SNAPSHOT_CACHE_KEY));
    }

    public function test_shared_snapshot_cache_stores_plain_array_projection_not_eloquent(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->createOpenIncident($admin);

        app(DashboardSnapshotStore::class)->get();

        $cached = Cache::get(OperatorDashboardCache::SNAPSHOT_CACHE_KEY);

        $this->assertIsArray($cached);
        $this->assertSame(ActiveIncidentSnapshotPayload::VERSION, $cached['v']);
        $this->assertIsArray($cached['incidents']);
        $this->assertNotEmpty($cached['incidents']);
        $this->assertSame('model', $cached['incidents'][0]['type']);
        $this->assertSame('incident', $cached['incidents'][0]['alias']);
        $this->assertIsArray($cached['incidents'][0]['attributes']);
    }

    #[DataProvider('serializingCacheStores')]
    public function test_snapshot_payload_round_trips_on_serializing_cache_store(string $store): void
    {
        $this->assertFalse((bool) config('cache.serializable_classes'));

        if ($store === 'redis') {
            try {
                Cache::store('redis')->get('operator.dashboard.snapshot.roundtrip.ping');
            } catch (\Throwable) {
                $this->markTestSkipped('Redis cache store is not available in this environment.');
            }
        }

        config(['cache.default' => $store]);
        Cache::store($store)->flush();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $incident = $this->createOpenIncident($admin);

        app()->forgetInstance(DashboardSnapshotStore::class);
        app()->forgetInstance(OperatorDashboardCache::class);

        $first = app(DashboardSnapshotStore::class)->get();
        $this->assertTrue(
            $first->activeIncidents()->contains(fn (Incident $row): bool => (int) $row->id === (int) $incident->id),
        );

        $raw = Cache::store($store)->get(OperatorDashboardCache::SNAPSHOT_CACHE_KEY);

        $this->assertIsArray($raw, 'Serializing store must revive a plain array, not Incomplete_Class.');
        $this->assertSame(ActiveIncidentSnapshotPayload::VERSION, $raw['v'] ?? null);

        app()->forgetInstance(DashboardSnapshotStore::class);
        app(\App\Services\Operations\OperationsQueueClassifier::class)->forgetClassifications();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $second = app(DashboardSnapshotStore::class)->get();

        $incidentQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), ' from "incidents"')
                || str_contains(strtolower($query['query']), ' from `incidents`'))
            ->count();

        DB::disableQueryLog();

        $this->assertSame(0, $incidentQueries);
        $this->assertTrue(
            $second->activeIncidents()->contains(fn (Incident $row): bool => (int) $row->id === (int) $incident->id),
        );
        $this->assertInstanceOf(Incident::class, $second->activeIncidents()->first());
        $this->assertTrue($second->activeIncidents()->first()->relationLoaded('order'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function serializingCacheStores(): array
    {
        return [
            'file' => ['file'],
            'database' => ['database'],
            'redis' => ['redis'],
        ];
    }

    public function test_close_invalidates_snapshot_when_hybrid_and_broadcast_are_off(): void
    {
        config([
            'system_settings.definitions.hybrid_realtime.close_resolve.default' => false,
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $incident = $this->createOpenIncident($admin);

        app(DashboardSnapshotStore::class)->get();
        $this->assertTrue(Cache::has(OperatorDashboardCache::SNAPSHOT_CACHE_KEY));

        app(ServiceCaseStatusService::class)->updateStatus(
            incident: $incident,
            status: IncidentStatus::Closed,
            actor: $admin,
            broadcast: false,
        );

        $this->assertFalse(
            Cache::has(OperatorDashboardCache::SNAPSHOT_CACHE_KEY),
            'Close must forget the snapshot without Hybrid Realtime or broadcast.',
        );
    }

    public function test_resolve_invalidates_snapshot_when_hybrid_and_broadcast_are_off(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $incident = $this->createOpenIncident($admin);

        app(DashboardSnapshotStore::class)->get();
        $this->assertTrue(Cache::has(OperatorDashboardCache::SNAPSHOT_CACHE_KEY));

        app(ServiceCaseStatusService::class)->updateStatus(
            incident: $incident,
            status: IncidentStatus::Resolved,
            actor: $admin,
            broadcast: false,
        );

        $this->assertFalse(Cache::has(OperatorDashboardCache::SNAPSHOT_CACHE_KEY));
    }

    public function test_reopen_invalidates_snapshot_independently_of_broadcast_side_effects(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $incident = $this->createOpenIncident($admin, ['status' => IncidentStatus::Closed]);

        // Warm after closed case is excluded from active snapshot.
        app(DashboardSnapshotStore::class)->get();
        $this->assertTrue(Cache::has(OperatorDashboardCache::SNAPSHOT_CACHE_KEY));

        app(ServiceCaseStatusService::class)->reopen($incident, $admin);

        $this->assertFalse(Cache::has(OperatorDashboardCache::SNAPSHOT_CACHE_KEY));
    }

    public function test_forget_clears_cross_request_snapshot_cache(): void
    {
        app(DashboardSnapshotStore::class)->get();
        $this->assertTrue(Cache::has(OperatorDashboardCache::SNAPSHOT_CACHE_KEY));

        app(DashboardSnapshotStore::class)->forget();

        $this->assertFalse(Cache::has(OperatorDashboardCache::SNAPSHOT_CACHE_KEY));
    }

    public function test_slow_scalars_are_cached_and_not_recounted_on_hot_path(): void
    {
        $superAdmin = User::factory()->create(['is_active' => true]);
        $superAdmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        foreach ([1, 2] as $index) {
            Order::query()->create([
                'order_id' => 'RB-SLOW-'.$index,
                'serial_number' => null,
                'product_name' => 'MFS110',
                'device_model' => 'MFS110',
                'status' => 'active',
                'created_by' => $superAdmin->id,
            ]);
        }

        AuditLog::query()->create([
            'user_id' => $superAdmin->id,
            'event' => 'test.cache',
            'auditable_type' => $superAdmin->getMorphClass(),
            'auditable_id' => $superAdmin->id,
            'new_values' => ['source' => 'operator-dashboard-cache-test'],
        ]);

        $expectedUsers = User::query()->count();
        $expectedAuditLogs = AuditLog::query()->count();

        $stats = app(DashboardService::class)->statsFor($superAdmin);

        $this->assertSame(2, $stats['total_orders']);
        $this->assertSame($expectedUsers, $stats['total_users']);
        $this->assertSame($expectedAuditLogs, $stats['audit_log_count']);
        $this->assertTrue(Cache::has(OperatorDashboardCache::SLOW_SCALARS_CACHE_KEY));

        DB::flushQueryLog();
        DB::enableQueryLog();

        $again = app(DashboardService::class)->statsFor($superAdmin);

        $countQueries = collect(DB::getQueryLog())
            ->filter(function (array $query): bool {
                $sql = strtolower($query['query']);

                return (str_contains($sql, 'count(') || str_contains($sql, 'count (*)'))
                    && (
                        str_contains($sql, ' from "orders"')
                        || str_contains($sql, ' from `orders`')
                        || str_contains($sql, ' from "users"')
                        || str_contains($sql, ' from `users`')
                        || str_contains($sql, ' from "audit_logs"')
                        || str_contains($sql, ' from `audit_logs`')
                    );
            })
            ->count();

        DB::disableQueryLog();

        $this->assertSame(2, $again['total_orders']);
        $this->assertSame(0, $countQueries, 'Warm slow-scalar cache must skip Order/User/AuditLog COUNTs.');
    }

    public function test_live_metrics_expose_fast_and_slow_splits(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $metrics = app(DashboardService::class)->liveMetricsFor($admin);

        $this->assertArrayHasKey('fast', $metrics);
        $this->assertArrayHasKey('slow', $metrics);
        $this->assertArrayHasKey('total_orders', $metrics['slow']);
        $this->assertArrayHasKey('online_count', $metrics['fast']);
        $this->assertArrayHasKey('kpi_strip_html', $metrics);
    }

    public function test_snapshot_ttl_is_clamped_to_15_30_seconds(): void
    {
        config(['dashboard.snapshot_cache_ttl_seconds' => 5]);
        $this->assertSame(15, app(OperatorDashboardCache::class)->snapshotTtlSeconds());

        config(['dashboard.snapshot_cache_ttl_seconds' => 90]);
        $this->assertSame(30, app(OperatorDashboardCache::class)->snapshotTtlSeconds());

        config(['dashboard.snapshot_cache_ttl_seconds' => 20]);
        $this->assertSame(20, app(OperatorDashboardCache::class)->snapshotTtlSeconds());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createOpenIncident(User $actor, array $attributes = []): Incident
    {
        $order = Order::query()->create([
            'order_id' => 'RB-CACHE-'.uniqid(),
            'serial_number' => null,
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Cache safety case',
            'description' => 'Snapshot cache hardening fixture.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
            ...$attributes,
        ]);
    }
}
