<?php

namespace Tests\Unit\Cases;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\WaitingReason;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\ReadModels\Cases\CaseQueueReadModel;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\DashboardBroadcastService;
use App\Services\DashboardPersonalizationService;
use App\Services\DashboardService;
use App\Services\IncidentReferenceService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CaseQueueOperatorConsumerMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
        app(DashboardSnapshotStore::class)->forget();
        Carbon::setTestNow(Carbon::parse('2026-07-20 11:40:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_stats_sla_counts_match_snapshot(): void
    {
        $this->seedReadyAndWaitingCases();
        $admin = $this->createAdmin();

        $snapshot = DashboardSnapshot::load();
        $stats = app(DashboardService::class)->statsFor($admin);

        $this->assertSame($snapshot->slaCounts()['overdue_cases'] ?? 0, $stats['overdue_cases']);
        $this->assertSame($snapshot->slaCounts()['warning_cases'] ?? 0, $stats['warning_cases']);
        $this->assertSame($snapshot->serviceSlaCounts()['overdue_cases'] ?? 0, $stats['service_overdue_cases']);
        $this->assertSame($snapshot->hardwareSlaCounts()['overdue_cases'] ?? 0, $stats['hardware_overdue_cases']);
    }

    public function test_dashboard_operational_kpis_match_read_model(): void
    {
        $this->seedReadyAndWaitingCases();
        $admin = $this->createAdmin();

        $snapshot = DashboardSnapshot::load();
        $readModel = app(CaseQueueReadModel::class);
        $stats = app(DashboardService::class)->statsFor($admin);

        $operational = $snapshot->operationalKpiCounts();
        $this->assertSame($operational['open_cases'], $stats['open_cases']);
        $this->assertSame($operational['waiting_cases'], $stats['waiting_cases']);
        $this->assertSame($readModel->operationalKpiCounts(snapshot: $snapshot)['open_cases'], $stats['open_cases']);
        $this->assertSame($readModel->waitingCount(snapshot: $snapshot), $stats['waiting_cases']);
    }

    public function test_service_case_filter_counts_match_snapshot(): void
    {
        $this->seedReadyAndWaitingCases();
        $admin = $this->createAdmin();

        $snapshot = DashboardSnapshot::load();
        $counts = app(DashboardService::class)->serviceCaseFilterCounts(null, $admin);

        $this->assertSame($snapshot->filterCounts(null, $admin), $counts);
        $this->assertSame(
            $snapshot->queueCounts()[OperationQueue::WaitingCustomer->value] ?? 0,
            $counts['waiting_customer'] ?? 0,
        );
    }

    public function test_live_reverb_metrics_filter_variants_unchanged(): void
    {
        $this->seedReadyAndWaitingCases();
        $agent = $this->createAgent();
        app(DashboardService::class)->forgetSnapshot();

        $metrics = app(DashboardService::class)->liveReverbMetricsFor($agent);
        $dashboardService = app(DashboardService::class);

        $this->assertSame(
            $dashboardService->serviceCaseFilterCounts(null, $agent),
            $metrics['service_case_filter_count_variants'][DashboardPersonalizationService::SCOPE_OPERATIONS],
        );
        $this->assertSame(
            $dashboardService->serviceCaseFilterCounts($agent, $agent),
            $metrics['service_case_filter_count_variants'][DashboardPersonalizationService::SCOPE_SUPPORT],
        );
    }

    public function test_dashboard_live_endpoint_stable_for_kpis_and_counts(): void
    {
        $this->seedReadyAndWaitingCases();
        $admin = $this->createAdmin();

        $first = $this->actingAs($admin)
            ->getJson(route('dashboard.live', ['queue' => DashboardPersonalizationService::QUEUE_ACTION_REQUIRED]))
            ->assertOk();

        $second = $this->actingAs($admin)
            ->getJson(route('dashboard.live', ['queue' => DashboardPersonalizationService::QUEUE_ACTION_REQUIRED]))
            ->assertOk();

        $this->assertSame($first->json('kpi_strip_html'), $second->json('kpi_strip_html'));
        $this->assertSame($first->json('service_case_filter_counts'), $second->json('service_case_filter_counts'));
    }

    public function test_reverb_kpis_updated_payload_matches_live_poll(): void
    {
        $this->seedReadyAndWaitingCases();
        $agent = $this->createAgent();
        $creator = $this->createAdmin();

        $liveKpiStrip = $this->actingAs($agent)
            ->getJson(route('dashboard.live', ['queue' => DashboardPersonalizationService::QUEUE_MY_WORK]))
            ->assertOk()
            ->json('kpi_strip_html');

        $supportCounts = $this->actingAs($agent)
            ->getJson(route('dashboard.live', ['queue' => DashboardPersonalizationService::QUEUE_MY_WORK]))
            ->assertOk()
            ->json('service_case_filter_counts');

        app(DashboardService::class)->forgetSnapshot();
        app(DashboardBroadcastService::class)->kpisUpdated($creator);

        $metrics = app(DashboardService::class)->liveReverbMetricsFor($agent);

        $this->assertSame($liveKpiStrip, $metrics['kpi_strip_html']);
        $this->assertSame(
            $supportCounts,
            $metrics['service_case_filter_count_variants'][DashboardPersonalizationService::SCOPE_SUPPORT],
        );
    }

    public function test_operator_summary_count_path_does_not_add_sql_beyond_snapshot(): void
    {
        $this->seedReadyAndWaitingCases();
        $admin = $this->createAdmin();
        app(DashboardSnapshotStore::class)->forget();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $snapshot = DashboardSnapshot::load();
        $snapshot->operationalKpiCounts();
        $snapshot->filterCounts(null, $admin);
        $snapshot->slaCounts();
        $snapshot->serviceSlaCounts();
        $snapshot->hardwareSlaCounts();
        $ownerCount = count(DB::getQueryLog());

        app(DashboardSnapshotStore::class)->forget();
        DB::flushQueryLog();
        $dashboard = app(DashboardService::class);
        $dashboard->forgetSnapshot();
        $dashboard->slaCounts();
        $dashboard->serviceCaseFilterCounts(null, $admin);
        $readModel = app(CaseQueueReadModel::class);
        $readModel->operationalKpiCounts(snapshot: $dashboard->snapshot());
        $consumerCount = count(DB::getQueryLog());

        $this->assertLessThanOrEqual($ownerCount, $consumerCount);
    }

    public function test_request_scoped_snapshot_cache_still_used_by_operator_consumer(): void
    {
        $this->seedReadyAndWaitingCases();
        $admin = $this->createAdmin();
        app(DashboardSnapshotStore::class)->forget();

        app(DashboardService::class)->statsFor($admin);

        DB::enableQueryLog();
        DB::flushQueryLog();
        app(CaseQueueReadModel::class)->queueCounts();
        app(CaseQueueReadModel::class)->slaCounts();
        $warmQueries = count(DB::getQueryLog());

        $this->assertSame(0, $warmQueries);
        $this->assertFalse(Cache::has('case-queue-read-model'));
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }

    private function createAgent(): User
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $agent;
    }

    /**
     * @return array{0: Incident, 1: Incident}
     */
    private function seedReadyAndWaitingCases(): array
    {
        $creator = User::factory()->create(['is_active' => true]);
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $ready = $this->createIncident('RD-H46E-READY', $creator, $creator);
        $waiting = $this->createIncident('RD-H46E-WAIT', $creator, $creator);
        IncidentWaitingState::query()->create([
            'incident_id' => $waiting->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now(),
            'sla_paused' => true,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        return [$ready, $waiting];
    }

    private function createIncident(string $orderId, User $creator, User $assignee): Incident
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'customer_name' => 'H4-6E Customer',
            'serial_number' => (string) (7_883_000 + (abs(crc32($orderId)) % 9_000)),
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'H4-6E operator consumer test',
            'description' => 'Operator dashboard summary migration seed.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $assignee->id,
        ]);
    }
}
