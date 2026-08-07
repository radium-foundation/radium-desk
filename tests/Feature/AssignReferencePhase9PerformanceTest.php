<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WorkspaceContext;
use App\Jobs\SendServiceReferenceDriverGuideBatchJob;
use App\Jobs\SendServiceReferenceDriverGuideJob;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Notifications\TransactionCompletedNotification;
use App\Services\AssignReferenceBatchCoalescer;
use App\Services\Automation\AutomationOperationsSnapshotInvalidator;
use App\Services\Dashboard\DashboardSnapshotStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Phase 9 — batch Assign Reference coalescing benchmarks (local).
 *
 * Asserts side-effect consolidation without changing commercial / audit /
 * lifecycle outcomes for a multi-order batch.
 */
class AssignReferencePhase9PerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_batch_assign_coalesces_driver_guide_snapshot_automation_and_preserves_audits(): void
    {
        Queue::fake();
        Notification::fake();

        $snapshotForgetCount = 0;
        $automationDirtyCount = 0;

        $snapshotStore = Mockery::mock(DashboardSnapshotStore::class)->makePartial();
        $snapshotStore->shouldReceive('forget')->andReturnUsing(function () use (&$snapshotForgetCount): void {
            $snapshotForgetCount++;
        });
        $this->app->instance(DashboardSnapshotStore::class, $snapshotStore);

        $invalidator = Mockery::mock(AutomationOperationsSnapshotInvalidator::class)->makePartial();
        $invalidator->shouldReceive('markCaseOrOrderChanged')->andReturnUsing(function () use (&$automationDirtyCount): void {
            $automationDirtyCount++;
        });
        $this->app->instance(AutomationOperationsSnapshotInvalidator::class, $invalidator);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $assignee = User::factory()->create(['is_active' => true]);
        $assignee->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incidentIds = [];
        $orderIds = [];

        for ($i = 1; $i <= 8; $i++) {
            $order = Order::query()->create([
                'order_id' => sprintf('RD-P9-%03d', $i),
                'serial_number' => sprintf('SN-P9-%03d', $i),
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
                'created_by' => $admin->id,
            ]);

            $this->actingAs($admin)
                ->postJson(route('orders.legacy-verification.store', $order), [
                    'confirmed' => true,
                ])
                ->assertOk();

            $incident = Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => sprintf('SC-P9-%03d', $i),
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => 'Phase 9 batch',
                'description' => 'Phase 9 batch.',
                'status' => IncidentStatus::Open->value,
                'created_by' => $admin->id,
                'assigned_to' => $assignee->id,
            ]);

            $incidentIds[] = $incident->id;
            $orderIds[] = $order->id;
        }

        $startedAt = microtime(true);

        $this->actingAs($admin)
            ->postJson(route('dashboard.workspace.batch-transaction'), [
                'incident_ids' => $incidentIds,
                'transaction_id' => 'TXN-P9-BATCH',
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('refresh.kpis', true);

        $httpWallMs = (int) round((microtime(true) - $startedAt) * 1000);

        Queue::assertNotPushed(SendServiceReferenceDriverGuideJob::class);
        Queue::assertPushed(SendServiceReferenceDriverGuideBatchJob::class, 1);
        Queue::assertPushed(SendServiceReferenceDriverGuideBatchJob::class, function (SendServiceReferenceDriverGuideBatchJob $job) use ($orderIds, $admin): bool {
            return $job->actorId === $admin->id
                && array_column($job->items, 'order_id') === $orderIds;
        });

        // One coalesced forget + at most one extra from ReferenceNumbersUpdated broadcast.
        $this->assertLessThanOrEqual(2, $snapshotForgetCount, 'snapshot forgets should be coalesced');
        $this->assertSame(1, $automationDirtyCount, 'automation dirty marks should be coalesced to one');

        $this->assertSame(
            8,
            \App\Models\AuditLog::query()->where('event', 'service_reference.assigned')->count(),
        );
        $this->assertSame(
            8,
            \App\Models\AuditLog::query()->where('event', 'transaction.assigned')->count(),
        );
        $this->assertSame(
            8,
            Incident::query()->whereIn('id', $incidentIds)->where('status', IncidentStatus::Closed)->count(),
        );

        Notification::assertSentToTimes($assignee, TransactionCompletedNotification::class, 8);

        $this->assertLessThan(
            15_000,
            $httpWallMs,
            "Phase 9 batch HTTP wall {$httpWallMs}ms exceeded local budget",
        );

        $this->assertFalse(app(AssignReferenceBatchCoalescer::class)->isActive());
    }

    public function test_single_assign_still_dispatches_per_order_driver_guide_job(): void
    {
        Queue::fake();

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-P9-SINGLE',
            'serial_number' => 'SN-P9-SINGLE',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson(route('orders.legacy-verification.store', $order), [
                'confirmed' => true,
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-P9-SINGLE',
            ])
            ->assertRedirect(route('orders.show', $order));

        Queue::assertPushed(SendServiceReferenceDriverGuideJob::class, 1);
        Queue::assertNotPushed(SendServiceReferenceDriverGuideBatchJob::class);
    }
}
