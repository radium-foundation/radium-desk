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
use App\Services\IncidentReferenceService;
use App\Services\Operations\IraMemoryService;
use App\Services\Operations\OperationsSupportIntelligenceService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CaseQueueOperationsConsumerMigrationTest extends TestCase
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

    public function test_support_intelligence_operational_metrics_match_snapshot(): void
    {
        $this->seedReadyAndWaitingCases();

        $snapshot = DashboardSnapshot::load();
        $queueCounts = $snapshot->queueCounts();
        $slaCounts = $snapshot->slaCounts();
        $serviceSla = $snapshot->serviceSlaCounts();
        $hardwareSla = $snapshot->hardwareSlaCounts();

        $summary = app(OperationsSupportIntelligenceService::class)->summary();
        $metrics = $summary->operationalMetrics;

        $this->assertSame($queueCounts[OperationQueue::ActionRequired->value] ?? 0, $metrics['action_required']);
        $this->assertSame($queueCounts[OperationQueue::WaitingCustomer->value] ?? 0, $metrics['waiting']);
        $this->assertSame($slaCounts['overdue_cases'] ?? 0, $metrics['total_overdue_cases']);
        $this->assertSame($slaCounts['warning_cases'] ?? 0, $metrics['total_warning_cases']);
        $this->assertSame($serviceSla['overdue_cases'] ?? 0, $metrics['service_overdue']);
        $this->assertSame($serviceSla['warning_cases'] ?? 0, $metrics['service_warning']);
        $this->assertSame($hardwareSla['overdue_cases'] ?? 0, $metrics['hardware_overdue']);
        $this->assertSame($hardwareSla['warning_cases'] ?? 0, $metrics['hardware_warning']);
        $this->assertSame(1, $metrics['waiting']);
        $this->assertGreaterThanOrEqual(1, $metrics['action_required']);
    }

    public function test_ira_memory_operations_counts_match_read_model_and_snapshot(): void
    {
        $this->seedReadyAndWaitingCases();
        app(IraMemoryService::class)->invalidateSnapshotDataCache();

        $snapshot = DashboardSnapshot::load();
        $readModel = app(CaseQueueReadModel::class);
        $operations = app(IraMemoryService::class)->collectSnapshotData()->operations;

        $this->assertSame($snapshot->openCount(), $operations['open_cases']);
        $this->assertSame($readModel->openCount(), $operations['open_cases']);
        $this->assertSame(
            $snapshot->queueCounts()[OperationQueue::WaitingCustomer->value] ?? 0,
            $operations['waiting'],
        );
        $this->assertSame(
            $snapshot->queueCounts()[OperationQueue::ActionRequired->value] ?? 0,
            $operations['action_required'],
        );
        $this->assertSame(
            $snapshot->queueCounts()[OperationQueue::Attention->value] ?? 0,
            $operations['attention'],
        );
        $this->assertSame(
            $snapshot->queueCounts()[OperationQueue::Scheduled->value] ?? 0,
            $operations['scheduled'],
        );
        $this->assertSame($snapshot->slaCounts()['overdue_cases'] ?? 0, $operations['total_overdue_cases']);
    }

    public function test_operations_live_critical_group_response_stable_for_queue_summaries(): void
    {
        $this->seedReadyAndWaitingCases();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $first = $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'critical,summary,health,ira_compact']))
            ->assertOk();

        $second = $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'critical,summary,health,ira_compact']))
            ->assertOk();

        $this->assertSame($first->json('html'), $second->json('html'));
    }

    public function test_migrated_summary_path_does_not_add_sql_vs_snapshot_counts(): void
    {
        $this->seedReadyAndWaitingCases();
        app(DashboardSnapshotStore::class)->forget();
        app(IraMemoryService::class)->invalidateSnapshotDataCache();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $snapshot = DashboardSnapshot::load();
        $snapshot->openCount();
        $snapshot->queueCounts();
        $snapshot->slaCounts();
        $snapshot->serviceSlaCounts();
        $snapshot->hardwareSlaCounts();
        $ownerCount = count(DB::getQueryLog());

        app(DashboardSnapshotStore::class)->forget();
        DB::flushQueryLog();
        $readModel = app(CaseQueueReadModel::class);
        $readModel->openCount();
        $readModel->queueCounts();
        $readModel->slaCounts();
        $readModel->serviceSlaCounts();
        $readModel->hardwareSlaCounts();
        $readModelCount = count(DB::getQueryLog());

        $this->assertLessThanOrEqual($ownerCount, $readModelCount);
    }

    public function test_request_scoped_snapshot_cache_still_used_by_migrated_consumers(): void
    {
        $this->seedReadyAndWaitingCases();
        app(DashboardSnapshotStore::class)->forget();
        app(IraMemoryService::class)->invalidateSnapshotDataCache();

        // Warm request snapshot via migrated summary path.
        app(OperationsSupportIntelligenceService::class)->summary();

        DB::enableQueryLog();
        DB::flushQueryLog();
        app(CaseQueueReadModel::class)->queueCounts();
        app(CaseQueueReadModel::class)->openCount();
        $warmQueries = count(DB::getQueryLog());

        $this->assertSame(0, $warmQueries, 'Migrated consumers must reuse DashboardSnapshotStore on subsequent count reads.');
        $this->assertFalse(Cache::has('case-queue-read-model'));
        $this->assertFalse(Cache::has('readmodel:case-queue'));
    }

    /**
     * @return array{0: Incident, 1: Incident}
     */
    private function seedReadyAndWaitingCases(): array
    {
        $creator = User::factory()->create(['is_active' => true]);
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $ready = $this->createIncident('RD-H46C-READY', $creator, $creator);
        $waiting = $this->createIncident('RD-H46C-WAIT', $creator, $creator);
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
            'customer_name' => 'H4-6C Customer',
            'serial_number' => (string) (7_881_000 + (abs(crc32($orderId)) % 9_000)),
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
            'title' => 'H4-6C case queue consumer test',
            'description' => 'Operations summary migration seed.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $assignee->id,
        ]);
    }
}
