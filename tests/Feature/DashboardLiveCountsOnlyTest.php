<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\DashboardPersonalizationService;
use App\Services\DashboardService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class DashboardLiveCountsOnlyTest extends TestCase
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

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_counts_only_returns_kpi_and_filter_counts_without_building_rows(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        for ($i = 0; $i < 5; $i++) {
            $this->createReadyQueueCase('RD-COUNTS-'.$i, $admin);
        }

        $real = $this->app->make(DashboardService::class);
        $spy = Mockery::mock($real)->makePartial();
        $spy->shouldReceive('serviceCasesPayload')->never();
        $this->app->instance(DashboardService::class, $spy);

        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.live', [
                'queue' => DashboardPersonalizationService::QUEUE_ACTION_REQUIRED,
                'kpis_only' => 1,
            ]))
            ->assertOk()
            ->assertJsonPath('kpis_only', true)
            ->assertJsonPath('rows', [])
            ->assertJsonPath('incident_ids', [])
            ->assertJsonPath('loaded_count', 0)
            ->assertJsonPath('has_more', false);

        $this->assertNotSame('', (string) $response->json('kpi_strip_html'));
        $counts = $response->json('service_case_filter_counts');
        $this->assertIsArray($counts);
        $this->assertSame(5, (int) $counts['action_required']);
        $this->assertSame(5, (int) $response->json('total_count'));
        $this->assertStringNotContainsString('service-case-row-', (string) $response->getContent());
    }

    public function test_full_live_still_builds_service_case_rows(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        for ($i = 0; $i < 3; $i++) {
            $this->createReadyQueueCase('RD-FULL-'.$i, $admin);
        }

        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.live', [
                'queue' => DashboardPersonalizationService::QUEUE_ACTION_REQUIRED,
            ]))
            ->assertOk()
            ->assertJsonCount(3, 'rows');

        $this->assertNull($response->json('kpis_only'));
        $this->assertStringContainsString('service-case-row-', (string) ($response->json('rows.0.html') ?? ''));
        $this->assertNotSame('', (string) $response->json('kpi_strip_html'));
        $this->assertSame(3, (int) $response->json('service_case_filter_counts.action_required'));
    }

    public function test_counts_only_uses_fewer_queries_and_smaller_payload_than_full_live(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        for ($i = 0; $i < 25; $i++) {
            $this->createReadyQueueCase('RD-BENCH-'.$i, $admin);
        }

        app(DashboardSnapshotStore::class)->forget();

        $this->actingAs($admin)
            ->getJson(route('dashboard.live', ['queue' => 'action_required']))
            ->assertOk();
        $this->actingAs($admin)
            ->getJson(route('dashboard.live', ['queue' => 'action_required', 'kpis_only' => 1]))
            ->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $fullStarted = hrtime(true);
        $full = $this->actingAs($admin)
            ->getJson(route('dashboard.live', ['queue' => 'action_required']))
            ->assertOk();
        $fullWallMs = (hrtime(true) - $fullStarted) / 1e6;
        $fullSql = count(DB::getQueryLog());
        $fullSqlMs = collect(DB::getQueryLog())->sum(fn (array $q): float => (float) ($q['time'] ?? 0));
        $fullBytes = strlen((string) $full->getContent());
        $fullRows = count($full->json('rows') ?? []);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $countsStarted = hrtime(true);
        $counts = $this->actingAs($admin)
            ->getJson(route('dashboard.live', ['queue' => 'action_required', 'kpis_only' => 1]))
            ->assertOk()
            ->assertJsonPath('kpis_only', true)
            ->assertJsonPath('rows', []);
        $countsWallMs = (hrtime(true) - $countsStarted) / 1e6;
        $countsSql = count(DB::getQueryLog());
        $countsSqlMs = collect(DB::getQueryLog())->sum(fn (array $q): float => (float) ($q['time'] ?? 0));
        $countsBytes = strlen((string) $counts->getContent());

        fwrite(STDERR, sprintf(
            "\nBENCH full: wall_ms=%.1f sql=%d sql_ms=%.1f bytes=%d rows=%d\nBENCH counts_only: wall_ms=%.1f sql=%d sql_ms=%.1f bytes=%d rows=0\n",
            $fullWallMs,
            $fullSql,
            $fullSqlMs,
            $fullBytes,
            $fullRows,
            $countsWallMs,
            $countsSql,
            $countsSqlMs,
            $countsBytes,
        ));

        $this->assertSame(
            (int) $full->json('service_case_filter_counts.action_required'),
            (int) $counts->json('service_case_filter_counts.action_required'),
        );
        $this->assertLessThan($fullSql, $countsSql);
        $this->assertLessThan($fullBytes, $countsBytes);
        $this->assertGreaterThan(0, $fullRows);
    }

    private function createReadyQueueCase(string $orderId, User $admin): Incident
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'B47C11929',
            'device_model' => 'Access FM220 L1',
            'product_name' => 'Access FM220 L1',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => "Case {$orderId}",
            'description' => "Awaiting product details for {$orderId}.",
            'status' => IncidentStatus::AwaitingProductDetails,
            'assigned_to_user_id' => $admin->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $fresh = $incident->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments']);
        $this->assertSame(
            DashboardPersonalizationService::QUEUE_ACTION_REQUIRED,
            app(OperationsQueueClassifier::class)->classify($fresh)->value,
        );

        return $fresh;
    }
}
