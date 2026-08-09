<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\ServiceCaseAutomationStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\AutomationOperationsSnapshotBuilder;
use App\Services\AutomationOperationsValidationCollector;
use App\Services\IncidentReferenceService;
use App\Services\ServiceCaseAutomationHealthService;
use App\Services\ServiceCaseAutomationStatusService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Optimization B: column-minimal active-incident load for Automation Snapshot.
 *
 * Proves projected Incident/Order/assignee selects match full-column semantics
 * for membership, statusFor, validation, queues, repair classification, and KPI counts.
 */
class AutomationSnapshotColumnMinimalActiveIncidentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        Cache::flush();
    }

    public function test_projected_loader_matches_full_column_semantics(): void
    {
        $this->seedRepresentativeCases();

        $health = app(ServiceCaseAutomationHealthService::class);
        $full = $health->activeIncidents()->sortBy('id')->values();
        $projected = $health->activeIncidentsForAutomationSnapshot()->sortBy('id')->values();

        $this->assertSame(
            $full->pluck('id')->all(),
            $projected->pluck('id')->all(),
            'Active incident membership must match',
        );
        $this->assertSame($full->count(), $projected->count());
        $this->assertGreaterThan(0, $projected->count());

        $fullStatuses = $this->statusesForFresh($full);
        $projectedStatuses = $this->statusesForFresh($projected);
        $this->assertSame($fullStatuses, $projectedStatuses, 'statusFor() must match for every incident');

        $fullCounts = $health->countsFor($full, $fullStatuses);
        $projectedCounts = $health->countsFor($projected, $projectedStatuses);
        $this->assertSame($fullCounts, $projectedCounts, 'Health counts / waiting / grace / repair must match');

        foreach ($full as $index => $fullIncident) {
            /** @var Incident $projectedIncident */
            $projectedIncident = $projected[$index];

            $this->assertSame(
                $health->isRepairCandidate($fullIncident),
                $health->isRepairCandidate($projectedIncident),
                "isRepairCandidate mismatch for incident {$fullIncident->id}",
            );

            $this->assertSame($fullIncident->assigned_to_user_id, $projectedIncident->assigned_to_user_id);
            $this->assertSame(
                $fullIncident->automation_pending_until?->toIso8601String(),
                $projectedIncident->automation_pending_until?->toIso8601String(),
            );
            $this->assertSame($fullIncident->display_reference, $projectedIncident->display_reference);
            $this->assertSame($fullIncident->status, $projectedIncident->status);

            $fullOrder = $fullIncident->order;
            $projectedOrder = $projectedIncident->order;

            $this->assertSame($fullOrder?->id, $projectedOrder?->id);
            $this->assertSame($fullOrder?->order_id, $projectedOrder?->order_id);
            $this->assertSame($fullOrder?->serial_number, $projectedOrder?->serial_number);
            $this->assertSame($fullOrder?->product_name, $projectedOrder?->product_name);
            $this->assertSame($fullOrder?->device_model, $projectedOrder?->device_model);
            $this->assertSame($fullOrder?->device_model_id, $projectedOrder?->device_model_id);
            $this->assertSame($fullOrder?->transaction_id, $projectedOrder?->transaction_id);
            $this->assertSame($fullOrder?->customer_name, $projectedOrder?->customer_name);
            $this->assertSame($fullOrder?->radiumbox_sync_status, $projectedOrder?->radiumbox_sync_status);
            $this->assertSame($fullOrder?->isTransactionLocked(), $projectedOrder?->isTransactionLocked());

            $fullAssignee = $fullIncident->assignee;
            $projectedAssignee = $projectedIncident->assignee;
            $this->assertSame($fullAssignee?->id, $projectedAssignee?->id);
            $this->assertSame($fullAssignee?->name, $projectedAssignee?->name);
            $this->assertSame(
                $fullAssignee?->getRoleNames()->sort()->values()->all() ?? [],
                $projectedAssignee?->getRoleNames()->sort()->values()->all() ?? [],
                "Assignee roles mismatch for incident {$fullIncident->id}",
            );
        }

        $fullOrders = $this->uniqueOrders($full);
        $projectedOrders = $this->uniqueOrders($projected);
        $this->assertSame(
            $fullOrders->pluck('id')->sort()->values()->all(),
            $projectedOrders->pluck('id')->sort()->values()->all(),
        );

        $collector = app(AutomationOperationsValidationCollector::class);
        $fullAnalysis = $collector->collectFromOrders($fullOrders, $fullStatuses);
        $projectedAnalysis = $collector->collectFromOrders($projectedOrders, $projectedStatuses);

        $this->assertSame($fullAnalysis->ordersScanned, $projectedAnalysis->ordersScanned);
        $this->assertSame($fullAnalysis->failureCount, $projectedAnalysis->failureCount);
        $this->assertSame($fullAnalysis->failuresByGroup, $projectedAnalysis->failuresByGroup);
        $this->assertSame($fullAnalysis->failuresByProduct, $projectedAnalysis->failuresByProduct);
        $this->assertSame($fullAnalysis->failuresByValidatorRule, $projectedAnalysis->failuresByValidatorRule);
        $this->assertSame(
            collect($fullAnalysis->failures)->map(fn ($f) => [
                $f->internalId,
                $f->externalOrderId,
                $f->serialNumber,
                $f->productName,
                $f->deviceModel,
                $f->failureGroup?->value,
                $f->failureReason,
            ])->all(),
            collect($projectedAnalysis->failures)->map(fn ($f) => [
                $f->internalId,
                $f->externalOrderId,
                $f->serialNumber,
                $f->productName,
                $f->deviceModel,
                $f->failureGroup?->value,
                $f->failureReason,
            ])->all(),
        );
    }

    public function test_snapshot_build_uses_projected_loader_and_matches_full_payload_contract(): void
    {
        $this->seedRepresentativeCases();

        $health = app(ServiceCaseAutomationHealthService::class);
        $full = $health->activeIncidents();
        $fullStatuses = $this->statusesForFresh($full);
        $fullCounts = $health->countsFor($full, $fullStatuses);

        $built = app(AutomationOperationsSnapshotBuilder::class)->buildDetailed();
        $snapshot = $built['data'];

        foreach ([
            'automation_pending',
            'waiting_over_5_min',
            'waiting_over_15_min',
            'unassigned',
            'grace_expired',
            'radiumbox_pending',
            'validation_failed',
            'waiting_for_customer_serial',
            'assigned_to_agent',
            'assigned_to_admin',
            'repair_needed',
        ] as $key) {
            $this->assertSame(
                $fullCounts[$key],
                $snapshot->healthCounts[$key] ?? null,
                "Snapshot healthCounts.{$key} must match full-column countsFor",
            );
        }

        $waitingExpected = $full
            ->filter(fn (Incident $incident): bool => ($fullStatuses[$incident->id] ?? null)
                === ServiceCaseAutomationStatus::WaitingForCustomerSerial)
            ->count();
        $this->assertCount($waitingExpected, $snapshot->waitingForCustomerSerialQueue);

        foreach ($snapshot->waitingForCustomerSerialQueue as $row) {
            $this->assertArrayHasKey('case_reference', $row);
            $this->assertArrayHasKey('order_id', $row);
            $this->assertArrayHasKey('customer_name', $row);
            $this->assertArrayHasKey('product', $row);
            $this->assertArrayHasKey('agent_name', $row);
            $this->assertNotSame('', (string) $row['case_reference']);
        }

        $this->assertCount($full->count(), $built['incident_stubs']);
        $this->assertIsArray($snapshot->validationByCategory);
        $this->assertNotNull($snapshot->repairStatistics);
    }

    public function test_projected_select_lists_are_strict_subsets_of_full_models(): void
    {
        $this->seedRepresentativeCases();

        $health = app(ServiceCaseAutomationHealthService::class);
        $full = $health->activeIncidents()->first();
        $projected = $health->activeIncidentsForAutomationSnapshot()->first();

        $this->assertNotNull($full);
        $this->assertNotNull($projected);

        $incidentProjected = array_keys($projected->getAttributes());
        $orderProjected = array_keys($projected->order?->getAttributes() ?? []);

        foreach (ServiceCaseAutomationHealthService::automationSnapshotIncidentColumns() as $column) {
            $this->assertContains($column, $incidentProjected);
        }

        foreach (ServiceCaseAutomationHealthService::automationSnapshotOrderColumns() as $column) {
            $this->assertContains($column, $orderProjected);
        }

        // Full models retain attributes that projected intentionally omits.
        $this->assertArrayHasKey('title', $full->getAttributes());
        $this->assertArrayNotHasKey('title', $projected->getAttributes());
        $this->assertArrayHasKey('description', $full->getAttributes());
        $this->assertArrayNotHasKey('description', $projected->getAttributes());

        if ($full->order !== null) {
            $this->assertArrayHasKey('status', $full->order->getAttributes());
            $this->assertArrayNotHasKey('status', $projected->order?->getAttributes() ?? []);
        }
    }

    public function test_benchmark_projected_vs_full_hydrate(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $ref = app(IncidentReferenceService::class);

        for ($i = 0; $i < 80; $i++) {
            $order = Order::query()->create([
                'order_id' => 'RB-CM-'.$i.'-'.uniqid(),
                'serial_number' => $i % 5 === 0 ? null : ('FPSPL1143'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)),
                'product_name' => 'MFS110',
                'device_model' => 'MFS110',
                'customer_name' => 'Bench Customer '.$i,
                'status' => 'active',
                'created_by' => $actor->id,
                'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::NotSynced,
            ]);

            Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => $ref->generate(),
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => 'Column minimal bench '.$i,
                'description' => str_repeat('x', 200),
                'status' => IncidentStatus::Open,
                'created_by' => $actor->id,
                'created_at' => now()->subMinutes($i + 3),
                'updated_at' => now()->subMinutes($i + 3),
            ]);
        }

        $health = app(ServiceCaseAutomationHealthService::class);

        $measure = function (callable $fn): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $started = hrtime(true);
            /** @var Collection<int, Incident> $rows */
            $rows = $fn();
            $ms = (hrtime(true) - $started) / 1e6;
            $queries = DB::getQueryLog();

            $attrBytes = 0;
            foreach ($rows as $row) {
                $attrBytes += strlen(serialize($row->getAttributes()));
                if ($row->relationLoaded('order') && $row->order !== null) {
                    $attrBytes += strlen(serialize($row->order->getAttributes()));
                }
            }

            $first = $rows->first();

            return [
                'ms' => round($ms, 1),
                'sql' => count($queries),
                'rows' => $rows->count(),
                'incident_columns' => $first !== null ? count($first->getAttributes()) : 0,
                'order_columns' => ($first?->order !== null) ? count($first->order->getAttributes()) : 0,
                'attr_bytes' => $attrBytes,
            ];
        };

        $full = $measure(fn (): Collection => $health->activeIncidents());
        $projected = $measure(fn (): Collection => $health->activeIncidentsForAutomationSnapshot());

        fwrite(STDERR, "\nCOLUMN_MINIMAL_BENCH ".json_encode([
            'full' => $full,
            'projected' => $projected,
        ], JSON_UNESCAPED_SLASHES)."\n");

        $this->assertSame($full['rows'], $projected['rows']);
        $this->assertLessThan($full['incident_columns'], $projected['incident_columns']);
        $this->assertLessThan($full['order_columns'], $projected['order_columns']);
        $this->assertLessThan($full['attr_bytes'], $projected['attr_bytes']);
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return array<int, ServiceCaseAutomationStatus>
     */
    private function statusesForFresh(Collection $incidents): array
    {
        $statusService = app(ServiceCaseAutomationStatusService::class);
        $map = [];

        foreach ($incidents as $incident) {
            $map[$incident->id] = $statusService->statusFor($incident);
        }

        return $map;
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return Collection<int, Order>
     */
    private function uniqueOrders(Collection $incidents): Collection
    {
        return $incidents
            ->filter(fn (Incident $incident): bool => $incident->order !== null)
            ->groupBy(fn (Incident $incident): int => (int) $incident->order_id)
            ->map(function (Collection $group): Order {
                /** @var Order $order */
                $order = $group->first()->order;
                $order->setRelation('incidents', $group->values());

                return $order;
            })
            ->values();
    }

    private function seedRepresentativeCases(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $agent = User::factory()->create(['is_active' => true, 'name' => 'Agent One']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $admin = User::factory()->create(['is_active' => true, 'name' => 'Admin One']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $ref = app(IncidentReferenceService::class);

        // Waiting for customer serial (null serial).
        $waitingOrder = Order::query()->create([
            'order_id' => 'RB-WAIT-'.uniqid(),
            'serial_number' => null,
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'customer_name' => 'Waiting Customer',
            'status' => 'active',
            'created_by' => $actor->id,
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::NotSynced,
        ]);
        Incident::query()->create([
            'order_id' => $waitingOrder->id,
            'reference_no' => $ref->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Waiting serial',
            'description' => 'Waiting serial',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $actor->id,
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(20),
        ]);

        // Automation pending (unassigned + future grace).
        $pendingOrder = Order::query()->create([
            'order_id' => 'RB-PEND-'.uniqid(),
            'serial_number' => 'FPSPL1141888',
            'product_name' => 'MFS100',
            'device_model' => 'MFS100',
            'customer_name' => 'Pending Customer',
            'status' => 'active',
            'created_by' => $actor->id,
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Pending,
        ]);
        Incident::query()->create([
            'order_id' => $pendingOrder->id,
            'reference_no' => $ref->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Automation pending',
            'description' => 'Automation pending',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => null,
            'automation_pending_until' => now()->addMinutes(10),
            'created_by' => $actor->id,
            'created_at' => now()->subMinutes(8),
            'updated_at' => now()->subMinutes(8),
        ]);

        // Assigned to admin.
        $adminOrder = Order::query()->create([
            'order_id' => 'RB-ADM-'.uniqid(),
            'serial_number' => 'FPSPL1141777',
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'customer_name' => 'Admin Customer',
            'status' => 'active',
            'created_by' => $actor->id,
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced,
        ]);
        Incident::query()->create([
            'order_id' => $adminOrder->id,
            'reference_no' => $ref->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Admin assigned',
            'description' => 'Admin assigned',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $admin->id,
            'created_by' => $actor->id,
            'created_at' => now()->subMinutes(40),
            'updated_at' => now()->subMinutes(40),
        ]);

        // Transaction-locked completed classification.
        $lockedOrder = Order::query()->create([
            'order_id' => 'RB-LOCK-'.uniqid(),
            'serial_number' => 'FPSPL1141666',
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'customer_name' => 'Locked Customer',
            'transaction_id' => 'TXN-LOCK-1',
            'status' => 'active',
            'created_by' => $actor->id,
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced,
        ]);
        Incident::query()->create([
            'order_id' => $lockedOrder->id,
            'reference_no' => $ref->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Locked order',
            'description' => 'Locked order',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $actor->id,
            'created_at' => now()->subMinutes(50),
            'updated_at' => now()->subMinutes(50),
        ]);

        // Grace expired unassigned.
        $graceOrder = Order::query()->create([
            'order_id' => 'RB-GRACE-'.uniqid(),
            'serial_number' => 'FPSPL1141555',
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'customer_name' => 'Grace Customer',
            'status' => 'active',
            'created_by' => $actor->id,
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::NotSynced,
        ]);
        Incident::query()->create([
            'order_id' => $graceOrder->id,
            'reference_no' => $ref->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Grace expired',
            'description' => 'Grace expired',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => null,
            'automation_pending_until' => now()->subMinutes(2),
            'created_by' => $actor->id,
            'created_at' => now()->subMinutes(25),
            'updated_at' => now()->subMinutes(25),
        ]);
    }
}
