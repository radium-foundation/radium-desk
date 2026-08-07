<?php

namespace Tests\Feature;

use App\Enums\AutomationSnapshotSlice;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Automation\AutomationOperationsSnapshotInvalidator;
use App\Services\AutomationOperationsSnapshotService;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutomationSnapshotPhase8InfrastructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        Cache::flush();
    }

    public function test_quiet_light_tick_stays_incremental_under_query_budget(): void
    {
        $this->seedActiveCase();

        $service = app(AutomationOperationsSnapshotService::class);
        $first = $service->refreshDetailed(forceReconcile: true);
        $this->assertTrue($first['rebuilt']);
        $this->assertSame('reconcile', $first['mode']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        $second = $service->refreshDetailed();
        $ms = (hrtime(true) - $started) / 1e6;
        $queries = count(DB::getQueryLog());

        $this->assertFalse($second['rebuilt']);
        $this->assertSame('incremental', $second['mode']);
        $this->assertLessThan(
            80,
            $queries,
            "Expected quiet light tick under 80 SQL queries, got {$queries} in ".round($ms, 1).'ms',
        );
    }

    public function test_dirty_health_slice_forces_full_rebuild_then_clears_flags(): void
    {
        $this->seedActiveCase();
        $service = app(AutomationOperationsSnapshotService::class);
        $invalidator = app(AutomationOperationsSnapshotInvalidator::class);

        $service->refreshDetailed(forceReconcile: true);
        $invalidator->markDirty(AutomationSnapshotSlice::Health);
        $this->assertTrue($invalidator->isDirty());

        $result = $service->refreshDetailed();

        $this->assertTrue($result['rebuilt']);
        $this->assertSame('full-rebuild', $result['mode']);
        $this->assertContains(AutomationSnapshotSlice::Health->value, $result['dirty_slices']);
        $this->assertFalse($invalidator->isDirty());
    }

    public function test_reconcile_command_reports_reconcile_mode(): void
    {
        $this->seedActiveCase();

        $this->artisan('automation:snapshot', ['--reconcile' => true])
            ->assertSuccessful()
            ->expectsOutput('Automation operations snapshot reconciled (full rebuild).');
    }

    public function test_mark_dirty_coalesces_slices(): void
    {
        $invalidator = app(AutomationOperationsSnapshotInvalidator::class);
        $invalidator->markCashfreeChanged();
        $invalidator->markCaseOrOrderChanged();

        $values = array_map(
            static fn (AutomationSnapshotSlice $slice): string => $slice->value,
            $invalidator->dirtySlices(),
        );

        $this->assertContains(AutomationSnapshotSlice::Cashfree->value, $values);
        $this->assertContains(AutomationSnapshotSlice::Health->value, $values);
        $this->assertContains(AutomationSnapshotSlice::Validation->value, $values);
        $this->assertContains(AutomationSnapshotSlice::RecentEvents->value, $values);
        $this->assertTrue($invalidator->requiresFullRebuild());
    }

    private function seedActiveCase(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => 'RB-P8-'.uniqid(),
            'serial_number' => 'FPSPL1141001',
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Phase 8 seed',
            'description' => 'Phase 8 seed',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);
    }
}
