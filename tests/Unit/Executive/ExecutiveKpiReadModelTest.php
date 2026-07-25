<?php

namespace Tests\Unit\Executive;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\ReadModels\Executive\ExecutiveKpiReadModel;
use App\Services\Executive\ExecutiveMetricsCache;
use App\Services\Executive\ExecutiveMetricsService;
use App\Services\IncidentReferenceService;
use App\Services\Platform\Cards\Executive\OpenCasesCardProvider;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExecutiveKpiReadModelTest extends TestCase
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

    public function test_dto_metrics_match_owner_snapshot_exactly(): void
    {
        $this->seedOpenAndCriticalCases();

        $owner = app(ExecutiveMetricsService::class)->snapshot();
        $dto = app(ExecutiveKpiReadModel::class)->metrics();

        $this->assertSame($owner->get('open_cases')->value, $dto->openCases);
        $this->assertSame($owner->get('critical_cases')->value, $dto->criticalCases);
        $this->assertSame($owner->get('refund_queue')->value, $dto->refundQueue);
        $this->assertSame($owner->get('active_agents')->value, $dto->activeAgents);
        $this->assertSame($owner->get('customers_waiting')->value, $dto->customersWaiting);
        $this->assertSame($owner->get('orders_today')->value, $dto->ordersToday);
        $this->assertSame($owner->get('resolved_today')->value, $dto->resolvedToday);
        $this->assertSame($owner->get('appointments_today')->value, $dto->appointmentsToday);
        $this->assertSame($owner->period, $dto->period);
        $this->assertSame(3, $dto->openCases);
        $this->assertSame(2, $dto->criticalCases);
        $this->assertSame(1, $dto->refundQueue);
    }

    public function test_delegate_get_and_snapshot_match_owner(): void
    {
        $this->seedOpenAndCriticalCases();

        $owner = app(ExecutiveMetricsService::class);
        $readModel = app(ExecutiveKpiReadModel::class);

        $this->assertSame(
            $owner->snapshot()->toArray(),
            $readModel->snapshot()->toArray(),
        );
        $this->assertSame(
            $owner->get('open_cases')->toArray(),
            $readModel->get('open_cases')->toArray(),
        );
    }

    public function test_mission_control_card_provider_matches_owner_open_cases(): void
    {
        $this->seedOpenAndCriticalCases();

        $ownerValue = app(ExecutiveMetricsService::class)->get('open_cases')->value;
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $payload = app(OpenCasesCardProvider::class)->load($viewer);

        $this->assertSame($ownerValue, $payload->meta['value'] ?? null);
        $this->assertSame('open_cases', $payload->meta['metric_id'] ?? null);
    }

    public function test_owner_cache_is_reused_and_read_model_adds_no_cache(): void
    {
        $this->seedOpenAndCriticalCases();

        $cache = app(ExecutiveMetricsCache::class);
        $dayKey = now()->toDateString();
        $period = \App\Data\Executive\ExecutiveMetricPeriod::Today;
        $cacheKey = $cache->key($period, $dayKey);

        $this->assertFalse(Cache::has($cacheKey));

        app(ExecutiveKpiReadModel::class)->snapshot();
        $this->assertTrue(Cache::has($cacheKey));

        DB::enableQueryLog();
        DB::flushQueryLog();

        app()->forgetInstance(ExecutiveMetricsService::class);
        app()->forgetInstance(ExecutiveKpiReadModel::class);

        $second = app(ExecutiveKpiReadModel::class)->metrics();
        $queryCount = count(DB::getQueryLog());

        $this->assertSame(3, $second->openCases);
        $this->assertSame(0, $queryCount, 'Owner 60s cache must satisfy ReadModel on hit.');

        $this->assertFalse(Cache::has('executive-kpi-read-model'));
        $this->assertFalse(Cache::has('ExecutiveKpiReadModel'));
        $this->assertFalse(Cache::has('readmodel:executive-kpi'));
    }

    public function test_read_model_snapshot_preserves_owner_query_count(): void
    {
        $this->seedOpenAndCriticalCases();
        Cache::flush();

        DB::enableQueryLog();
        DB::flushQueryLog();
        app(ExecutiveMetricsService::class)->snapshot();
        $ownerQueryCount = count(DB::getQueryLog());

        Cache::flush();
        app()->forgetInstance(ExecutiveMetricsService::class);
        app()->forgetInstance(ExecutiveKpiReadModel::class);

        DB::flushQueryLog();
        app(ExecutiveKpiReadModel::class)->snapshot();
        $readModelQueryCount = count(DB::getQueryLog());

        $this->assertSame($ownerQueryCount, $readModelQueryCount);
    }

    public function test_platform_executive_card_json_unchanged_across_repeat_requests(): void
    {
        $this->seedOpenAndCriticalCases();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $first = $this->actingAs($admin)
            ->getJson(route('admin.platform.cards.show', ['card' => 'exec_open_cases']))
            ->assertOk();

        $second = $this->actingAs($admin)
            ->getJson(route('admin.platform.cards.show', ['card' => 'exec_open_cases']))
            ->assertOk();

        $this->assertSame($first->json(), $second->json());
        $this->assertSame(3, data_get($first->json('payload'), 'meta.value'));
    }

    private function seedOpenAndCriticalCases(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->createIncident($actor, IncidentStatus::Open);
        $this->createIncident($actor, IncidentStatus::Open, highPriority: true);
        $critical = $this->createIncident($actor, IncidentStatus::InProgress, highPriority: true);

        RefundRequest::query()->create([
            'order_id' => $critical->order_id,
            'incident_id' => $critical->id,
            'reference_no' => 'REF-H4-5-0001',
            'amount' => 1000,
            'reason' => 'Executive KPI read model test',
            'status' => RefundStatus::Pending,
            'requested_by' => $actor->id,
        ]);
    }

    private function createIncident(User $actor, IncidentStatus $status, bool $highPriority = false): Incident
    {
        $order = Order::query()->create([
            'order_id' => 'RD-H45-'.uniqid(),
            'customer_name' => 'Executive KPI Customer',
            'serial_number' => 'FPSPL1141XX',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Executive KPI test case',
            'description' => 'Executive KPI test case.',
            'status' => $status,
            'high_priority' => $highPriority,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);
    }
}
