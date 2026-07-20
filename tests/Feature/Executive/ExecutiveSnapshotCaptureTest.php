<?php

namespace Tests\Feature\Executive;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\ExecutiveMetricSnapshot;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Executive\ExecutiveMetricsService;
use App\Services\Executive\Snapshots\ExecutiveSnapshotService;
use App\Services\IncidentReferenceService;
use App\Services\Platform\PlatformDashboardService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ExecutiveSnapshotCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-07-20 11:40:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createSuperadmin(): User
    {
        $user = User::factory()->create([
            'email' => 'snap-superadmin@test.com',
            'is_active' => true,
            'password' => bcrypt('password'),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }

    private function createOpenIncident(User $actor): void
    {
        $order = Order::query()->create([
            'order_id' => 'RD-SNAP-'.uniqid(),
            'customer_name' => 'Snap Customer',
            'serial_number' => 'FPSPL1141XX',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Snapshot test case',
            'description' => 'Snapshot test case.',
            'status' => IncidentStatus::Open,
            'high_priority' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);
    }

    public function test_artisan_capture_writes_all_registered_metrics_once_per_hour(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $this->createOpenIncident($actor);

        $this->artisan('executive:snapshot')->assertSuccessful();

        $this->assertSame(8, ExecutiveMetricSnapshot::query()->count());
        $this->assertTrue(
            ExecutiveMetricSnapshot::query()->where('metric_key', 'open_cases')->exists(),
        );

        $this->artisan('executive:snapshot')->assertSuccessful();
        $this->assertSame(8, ExecutiveMetricSnapshot::query()->count());
    }

    public function test_card_refresh_does_not_write_historical_snapshots(): void
    {
        $superadmin = $this->createSuperadmin();
        $actor = User::factory()->create(['is_active' => true]);
        $this->createOpenIncident($actor);

        app(PlatformDashboardService::class)->build($superadmin);
        $this->assertSame(0, ExecutiveMetricSnapshot::query()->count());

        $this->actingAs($superadmin)
            ->getJson(route('admin.platform.cards.show', ['card' => 'exec_open_cases']))
            ->assertOk();

        $this->assertSame(0, ExecutiveMetricSnapshot::query()->count());
    }

    public function test_capture_service_reuses_live_metrics_snapshot(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $this->createOpenIncident($actor);

        $result = app(ExecutiveSnapshotService::class)->capture();

        $this->assertSame(8, $result['written']);
        $this->assertCount(8, $result['snapshot']->metrics);
        $this->assertSame(1, $result['snapshot']->get('open_cases')->value);

        $live = app(ExecutiveMetricsService::class)->get('open_cases');
        $this->assertSame(1, $live->value);
    }

    public function test_enriched_live_metrics_include_trend_fields_after_history_exists(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $this->createOpenIncident($actor);

        app(ExecutiveSnapshotService::class)->capture(now()->subDay());

        app()->forgetInstance(ExecutiveMetricsService::class);
        Cache::flush();

        $this->createOpenIncident($actor);
        $metric = app(ExecutiveMetricsService::class)->get('open_cases', force: true);

        $this->assertNotNull($metric->previousValue);
        $this->assertSame(1.0, $metric->previousValue);
        $this->assertSame(100.0, $metric->trendPercentage);
        $this->assertNotNull($metric->comparisonLabel);
    }
}
