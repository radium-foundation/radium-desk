<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardLivePhase1PerformanceTest extends TestCase
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

    public function test_live_refresh_stays_under_query_budget_for_admin(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        for ($i = 0; $i < 40; $i++) {
            $this->createOpenIncident($admin, $i);
        }

        $this->actingAs($admin)
            ->getJson(route('dashboard.live'))
            ->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $started = hrtime(true);
        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.live'))
            ->assertOk();
        $elapsedMs = (hrtime(true) - $started) / 1e6;

        $queries = collect(DB::getQueryLog());
        $queryCount = $queries->count();
        $payloadBytes = strlen((string) $response->getContent());

        $cacheSelects = $queries
            ->filter(fn (array $q): bool => str_contains(strtolower($q['query']), 'from `cache`')
                || str_contains(strtolower($q['query']), 'from "cache"'))
            ->count();

        $top = $queries
            ->map(fn (array $q): string => preg_replace('/\s+/', ' ', $q['query']) ?? $q['query'])
            ->countBy()
            ->sortDesc()
            ->take(10)
            ->map(fn (int $count, string $sql): string => "{$count}× {$sql}")
            ->values()
            ->all();

        $this->assertLessThan(
            100,
            $queryCount,
            "Expected <100 SQL queries on warm live refresh, got {$queryCount}"
            ." (cache selects={$cacheSelects}, ms=".round($elapsedMs, 1)
            .', bytes='.$payloadBytes.', top='.implode(' | ', $top).')',
        );

        $this->assertLessThan(
            20,
            $cacheSelects,
            "Expected SettingService/RadiumBox cache storm eliminated, got {$cacheSelects} cache SELECTs",
        );

        $this->assertNotEmpty($response->json('kpi_strip_html'));
        $this->assertIsArray($response->json('service_case_filter_counts'));
        $this->assertIsArray($response->json('rows'));
    }

    private function createOpenIncident(User $actor, int $index): Incident
    {
        $order = Order::query()->create([
            'order_id' => 'RB-PERF-'.$index.'-'.uniqid(),
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
            'title' => 'Phase 1 perf case '.$index,
            'description' => 'Dashboard live Phase 1 performance fixture.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);
    }
}
