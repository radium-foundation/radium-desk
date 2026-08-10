<?php

namespace Tests\Feature\Dashboard;

use App\Events\Dashboard\DashboardKpisUpdated;
use App\Models\User;
use App\Services\DashboardBroadcastService;
use App\Services\DashboardService;
use App\Services\Operations\OperationsQueueClassifier;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DashboardBroadcastKpiBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        Cache::flush();
    }

    public function test_kpis_updated_reuses_shared_filter_count_computation(): void
    {
        Event::fake([DashboardKpisUpdated::class]);

        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = \App\Models\Order::query()->create([
            'order_id' => 'RB-BATCH-1',
            'serial_number' => 'SN-BATCH-1',
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        \App\Models\Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(\App\Services\IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => \App\Enums\IncidentSource::Call,
            'title' => 'Batch KPI case',
            'description' => 'Batch KPI case',
            'status' => \App\Enums\IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $viewerCount = 6;
        for ($i = 0; $i < $viewerCount; $i++) {
            $viewer = User::factory()->create(['is_active' => true]);
            $viewer->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        }

        $classifier = app(OperationsQueueClassifier::class);
        $classifier->forgetClassifications();

        app(DashboardBroadcastService::class)->kpisUpdated($actor);

        Event::assertDispatchedTimes(DashboardKpisUpdated::class, $viewerCount);

        $activeCount = 1;
        $this->assertSame(
            $activeCount,
            $classifier->classificationComputeCount(),
            'KPI broadcast batch should classify each incident once, not per recipient.',
        );
    }
}
