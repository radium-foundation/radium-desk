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
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CaseQueueReadModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
        app(DashboardSnapshotStore::class)->forget();
    }

    public function test_metrics_dto_matches_dashboard_snapshot_exactly(): void
    {
        $this->seedReadyAndWaitingCases();

        $snapshot = DashboardSnapshot::load();
        $dto = app(CaseQueueReadModel::class)->metrics(snapshot: $snapshot);

        $operational = $snapshot->operationalKpiCounts();
        $this->assertSame((int) $operational['open_cases'], $dto->openCases);
        $this->assertSame((int) $operational['waiting_cases'], $dto->waitingCases);
        $this->assertSame($snapshot->queueCounts(), $dto->queueCounts);
        $this->assertSame($snapshot->slaCounts(), $dto->slaCounts);
        $this->assertSame(1, $dto->waitingCases);
        $this->assertGreaterThanOrEqual(1, $dto->openCases);
        $this->assertSame(1, $dto->queueCount(OperationQueue::WaitingCustomer));
    }

    public function test_delegate_counts_match_snapshot_byte_for_byte(): void
    {
        $this->seedReadyAndWaitingCases();

        $snapshot = DashboardSnapshot::load();
        $readModel = app(CaseQueueReadModel::class);

        $this->assertSame($snapshot->openCount(), $readModel->openCount(snapshot: $snapshot));
        $this->assertSame($snapshot->waitingCount(), $readModel->waitingCount(snapshot: $snapshot));
        $this->assertSame($snapshot->queueCounts(), $readModel->queueCounts(snapshot: $snapshot));
        $this->assertSame($snapshot->slaCounts(), $readModel->slaCounts(snapshot: $snapshot));
        $this->assertSame(
            $snapshot->operationalKpiCounts(),
            $readModel->operationalKpiCounts(snapshot: $snapshot),
        );
        $this->assertSame(
            $snapshot->filterCounts(),
            $readModel->filterCounts(snapshot: $snapshot),
        );
    }

    public function test_queue_membership_unchanged_via_classifier_delegate(): void
    {
        [$ready, $waiting] = $this->seedReadyAndWaitingCases();

        $classifier = app(OperationsQueueClassifier::class);
        $readModel = app(CaseQueueReadModel::class);

        $readyFresh = $ready->fresh(['activeWaitingState', 'order', 'supportAppointments', 'activeBusinessHold']);
        $waitingFresh = $waiting->fresh(['activeWaitingState', 'order', 'supportAppointments', 'activeBusinessHold']);

        $this->assertSame(
            $classifier->classify($readyFresh),
            $readModel->classify($readyFresh),
        );
        $this->assertSame(
            $classifier->classify($waitingFresh),
            $readModel->classify($waitingFresh),
        );
        $this->assertSame(OperationQueue::ActionRequired, $readModel->classify($readyFresh));
        $this->assertSame(OperationQueue::WaitingCustomer, $readModel->classify($waitingFresh));
    }

    public function test_incidents_for_queue_match_snapshot_membership(): void
    {
        [$ready, $waiting] = $this->seedReadyAndWaitingCases();

        $snapshot = DashboardSnapshot::load();
        $readModel = app(CaseQueueReadModel::class);

        $readyIds = $snapshot->incidentsForQueue(OperationQueue::ActionRequired->value)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();
        $waitingIds = $snapshot->incidentsForQueue(OperationQueue::WaitingCustomer->value)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            $readyIds,
            $readModel->incidentsForQueue(OperationQueue::ActionRequired, snapshot: $snapshot)
                ->pluck('id')
                ->sort()
                ->values()
                ->all(),
        );
        $this->assertSame(
            $waitingIds,
            $readModel->incidentsForQueue(OperationQueue::WaitingCustomer, snapshot: $snapshot)
                ->pluck('id')
                ->sort()
                ->values()
                ->all(),
        );
        $this->assertContains($ready->id, $readyIds);
        $this->assertContains($waiting->id, $waitingIds);
    }

    public function test_read_model_adds_no_sql_beyond_snapshot_owner_path(): void
    {
        $this->seedReadyAndWaitingCases();
        app(DashboardSnapshotStore::class)->forget();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $snapshot = DashboardSnapshot::load();
        $snapshot->operationalKpiCounts();
        $snapshot->queueCounts();
        $snapshot->slaCounts();
        $ownerQueryCount = count(DB::getQueryLog());

        app(DashboardSnapshotStore::class)->forget();
        DB::flushQueryLog();
        $readModel = app(CaseQueueReadModel::class);
        $readModel->metrics();
        $readModelQueryCount = count(DB::getQueryLog());

        $this->assertLessThanOrEqual(
            $ownerQueryCount,
            $readModelQueryCount,
            'Shadow ReadModel must not add SQL beyond DashboardSnapshot owner path.',
        );
    }

    public function test_request_scoped_snapshot_cache_behaviour_unchanged(): void
    {
        $this->seedReadyAndWaitingCases();
        app(DashboardSnapshotStore::class)->forget();

        DB::enableQueryLog();
        DB::flushQueryLog();

        app(CaseQueueReadModel::class)->openCount();
        app(CaseQueueReadModel::class)->waitingCount();
        app(CaseQueueReadModel::class)->queueCounts();
        $incidentQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains(strtolower($query), 'from "incidents"')
                || str_contains(strtolower($query), 'from `incidents`'))
            ->count();

        $this->assertSame(1, $incidentQueries, 'DashboardSnapshotStore must still load incidents once per request.');

        $this->assertFalse(Cache::has('case-queue-read-model'));
        $this->assertFalse(Cache::has('CaseQueueReadModel'));
        $this->assertFalse(Cache::has('readmodel:case-queue'));
    }

    public function test_only_allowlisted_case_queue_read_model_consumers_exist(): void
    {
        // H4-6C + H4-6D approved production consumers (summary counts only).
        $allowlist = [
            'DashboardService.php',
            'OperationsSupportIntelligenceService.php',
            'IraMemoryService.php',
            'IraOwnerIntelligenceService.php',
            'TeamAvailabilityOverviewService.php',
            'Workforce360Service.php',
            'IraReadyQueueDigestContextService.php',
            'TeamActivityPendingMetricsService.php',
        ];

        $roots = [
            base_path('app/Http/Controllers'),
            base_path('app/Services'),
            base_path('app/Providers'),
        ];

        $hits = collect();

        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = $file->getPathname();

                if (str_contains($path, DIRECTORY_SEPARATOR.'ReadModels'.DIRECTORY_SEPARATOR)) {
                    continue;
                }

                $contents = file_get_contents($path);

                if (is_string($contents) && str_contains($contents, 'CaseQueueReadModel')) {
                    $hits->push(basename($path));
                }
            }
        }

        $unexpected = $hits->reject(fn (string $file): bool => in_array($file, $allowlist, true))->values();

        $this->assertSame(
            collect($allowlist)->sort()->values()->all(),
            $hits->unique()->sort()->values()->all(),
            'CaseQueueReadModel allowlist mismatch. Unexpected: '.$unexpected->implode(', '),
        );
    }

    public function test_remaining_dashboard_snapshot_load_sites_are_intentional_keep_list(): void
    {
        // Post H4-6D: every production DashboardSnapshot::load() must be intentional KEEP.
        $expectedKeep = collect([
            'DashboardService.php',
            'IraAdminOpsDigestContextService.php',
            'IraCommunicationService.php',
            'IraOwnerIntelligenceService.php',
            'IraRecommendationEngineService.php',
            'IraRiskDetectionService.php',
            'OperationsSupportIntelligenceService.php',
            'SmartAssignmentFeedbackMetricsService.php',
            'SmartAssignmentService.php',
            'SupportAssignmentWorkloadService.php',
            'SupportSlotReminderService.php',
            'TeamWorkBriefingService.php',
        ])->sort()->values()->all();

        $hits = collect();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            if (str_contains($path, DIRECTORY_SEPARATOR.'ReadModels'.DIRECTORY_SEPARATOR)
                || str_contains($path, 'DashboardSnapshot.php')
                || str_contains($path, 'DashboardSnapshotStore.php')) {
                continue;
            }

            $contents = file_get_contents($path);

            // Match DashboardSnapshot::load(, not OperationsDashboardSnapshot::load(.
            if (is_string($contents) && preg_match('/(?<!Operations)DashboardSnapshot::load\(/', $contents) === 1) {
                $hits->push(basename($path));
            }
        }

        $this->assertSame(
            $expectedKeep,
            $hits->unique()->sort()->values()->all(),
            'Unexpected DashboardSnapshot::load() site. Update H4-6 KEEP allowlist before changing.',
        );
    }

    /**
     * @return array{0: Incident, 1: Incident}
     */
    private function seedReadyAndWaitingCases(): array
    {
        $creator = User::factory()->create(['is_active' => true]);
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $ready = $this->createIncident('RD-H46B-READY', $creator, $creator);

        $waiting = $this->createIncident('RD-H46B-WAIT', $creator, $creator);
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
            'customer_name' => 'Case Queue Customer',
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
            'title' => 'Case queue read model test',
            'description' => 'H4-6B shadow CaseQueueReadModel seed.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $assignee->id,
        ]);
    }
}
