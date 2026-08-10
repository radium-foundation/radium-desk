<?php

namespace Tests\Feature\Dashboard;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Dashboard\DashboardClassificationIndex;
use App\Services\Dashboard\DashboardIncidentQueueMembership;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\DashboardService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardClassificationIndexTest extends TestCase
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

    public function test_classification_runs_once_per_incident_for_filter_counts(): void
    {
        $creator = User::factory()->create(['is_active' => true]);
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $activeCount = 8;

        for ($index = 1; $index <= $activeCount; $index++) {
            $order = Order::query()->create([
                'order_id' => 'RB-IDX-'.$index,
                'serial_number' => 'SN-IDX-'.$index,
                'product_name' => 'MFS110',
                'device_model' => 'MFS110',
                'status' => 'active',
                'created_by' => $creator->id,
            ]);

            Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => app(IncidentReferenceService::class)->generate(),
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => 'Index case '.$index,
                'description' => 'Index case '.$index,
                'status' => IncidentStatus::Open,
                'created_by' => $creator->id,
                'updated_by' => $creator->id,
            ]);
        }

        $classifier = app(OperationsQueueClassifier::class);
        $classifier->forgetClassifications();

        app(DashboardService::class)->serviceCaseFilterCounts();

        $this->assertSame(
            $activeCount,
            $classifier->classificationComputeCount(),
            'Classification index should classify each active incident once per request.',
        );

        $before = $classifier->classificationComputeCount();
        app(DashboardService::class)->serviceCaseFilterCounts();
        $this->assertSame($before, $classifier->classificationComputeCount());
    }

    public function test_lean_load_skips_row_only_relations(): void
    {
        $creator = User::factory()->create(['is_active' => true]);
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RB-LEAN-1',
            'serial_number' => 'SN-LEAN-1',
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Lean case',
            'description' => 'Lean case body should not be required for counts',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $snapshot = app(DashboardClassificationIndex::class)->getSnapshot();
        $incident = $snapshot->activeIncidents()->first();

        $queries = collect(DB::getQueryLog())->pluck('query')->map(strtolower(...));
        DB::disableQueryLog();

        $this->assertNotNull($incident);
        $this->assertFalse($incident->relationLoaded('creator'));
        $this->assertFalse($incident->relationLoaded('refundRequests'));
        $this->assertFalse(
            $queries->contains(fn (string $query): bool => str_contains($query, 'refund_requests')),
            'Lean classification load should not query refund_requests.',
        );
    }

    public function test_row_visibility_matches_snapshot_membership(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'email' => 'idx-admin@test.com']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $agent = User::factory()->create(['is_active' => true, 'email' => 'idx-agent@test.com']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RB-VIS-1',
            'serial_number' => 'SN-VIS-1',
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Visibility case',
            'description' => 'Visibility case',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $snapshot = app(DashboardClassificationIndex::class)->getSnapshot();
        $membership = app(DashboardIncidentQueueMembership::class);
        $queues = OperationQueue::cases();

        foreach ($queues as $queue) {
            $scopeUser = app(\App\Services\DashboardPersonalizationService::class)
                ->resolveAssignedToScope($admin, $queue->value);

            $snapshotVisible = $snapshot
                ->incidentsForQueue($queue->value, $scopeUser)
                ->contains(fn (Incident $case): bool => $case->id === $incident->id);

            $singleVisible = $membership->isVisibleInQueue(
                $incident->fresh([
                    'order',
                    'assignee.roles',
                    'activeWaitingState',
                    'activeBusinessHold',
                    'supportAppointments',
                ]),
                $queue->value,
                $scopeUser,
            );

            $this->assertSame(
                $snapshotVisible,
                $singleVisible,
                "Queue membership parity failed for {$queue->value}",
            );
        }
    }

    public function test_lean_snapshot_precomputes_sla_and_overdue_filter_counts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 15:00:00'));

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-SLA-IDX',
            'serial_number' => 'SN-SLA-IDX',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        foreach ([
            ['ref' => 'SC-IDX-WARN', 'hours' => 26],
            ['ref' => 'SC-IDX-OVER', 'hours' => 60],
            ['ref' => 'SC-IDX-OK', 'hours' => 3],
        ] as $case) {
            $createdAt = now()->subHours($case['hours']);

            $incident = Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => $case['ref'],
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => $case['ref'],
                'description' => $case['ref'],
                'status' => IncidentStatus::Open,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);

            $incident->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->saveQuietly();
        }

        $snapshot = app(DashboardClassificationIndex::class)->getSnapshot();
        $sla = $snapshot->slaCounts();
        $filters = $snapshot->filterCounts();

        $this->assertSame(1, $sla['overdue_cases']);
        $this->assertSame(1, $filters['overdue']);
        $this->assertSame(1, $filters['warning']);

        Carbon::setTestNow();
    }

    public function test_hydrate_incidents_for_row_rendering_includes_source(): void
    {
        $creator = User::factory()->create(['is_active' => true]);
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RB-HYDRATE-1',
            'serial_number' => '1234567890',
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Hydrate case',
            'description' => 'Hydrate case',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        $lean = app(DashboardClassificationIndex::class)->loadLeanIncidents();
        $leanIncident = $lean->firstWhere('id', $incident->id);

        $this->assertNotNull($leanIncident);
        $this->assertFalse(array_key_exists('source', $leanIncident->getAttributes()));

        $hydrated = app(DashboardService::class)
            ->hydrateIncidentsForRowRendering(collect([$leanIncident]))
            ->first();

        $this->assertNotNull($hydrated?->source);
        $this->assertSame(
            IncidentSource::Call,
            $hydrated->source,
        );

        view('dashboard.partials.source-icon', ['source' => $hydrated->source])->render();
    }
}
