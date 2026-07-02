<?php

namespace Tests\Unit\Knowledge;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\AI\CustomerScopeQueryCache;
use App\Services\IncidentReferenceService;
use App\Services\Knowledge\KnowledgeAggregationCache;
use App\Services\Knowledge\KnowledgeEngine;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KnowledgeEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_aggregates_customer_and_repair_knowledge(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-KNOW-1',
            'serial_number' => 'SN-KNOW-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9000000101',
            'payment_amount' => 2500,
            'payment_date' => now()->subDays(10),
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $closed = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'Hardware',
            'source' => IncidentSource::Call,
            'title' => 'Screen fault',
            'description' => 'Closed.',
            'status' => IncidentStatus::Closed,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);
        $closed->forceFill(['created_at' => now()->subDays(5), 'updated_at' => now()->subDays(2)])->save();

        $current = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'Hardware',
            'source' => IncidentSource::Call,
            'title' => 'Screen fault',
            'description' => 'Open.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $knowledge = app(KnowledgeEngine::class)->forIncident($current);

        $this->assertSame(1, $knowledge->repair->similarIncidentCount);
        $this->assertTrue($knowledge->customer->repeatIssueDetected);
        $this->assertSame(2500.0, $knowledge->business->customerLifetimeValue);
        $this->assertNotEmpty($knowledge->knowledgeSummary);
        $this->assertSame($agent->name, $knowledge->repair->previousTechnician);
    }

    public function test_detects_common_resolution_from_closed_incidents(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-KNOW-2',
            'serial_number' => 'SN-KNOW-2',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9000000102',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        foreach (range(1, 2) as $index) {
            Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => app(IncidentReferenceService::class)->generate(),
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => 'Case '.$index,
                'description' => 'Closed.',
                'status' => IncidentStatus::Closed,
                'created_by' => $agent->id,
                'updated_by' => $agent->id,
            ]);
        }

        $current = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'New case',
            'description' => 'Open.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $knowledge = app(KnowledgeEngine::class)->forIncident($current);

        $this->assertSame('Closed', $knowledge->repair->mostCommonResolution);
        $this->assertGreaterThan(0, $knowledge->repair->historicalSuccessRate);
    }

    public function test_caches_aggregations_within_single_build(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-KNOW-3',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9000000103',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Cache test',
            'description' => 'Open.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $scopeCache = new CustomerScopeQueryCache($order->customer_phone);
        $aggregation = new KnowledgeAggregationCache($scopeCache->incidentsWithAssignee(), $incident);

        $first = $aggregation->averageRepairTurnaroundDays();
        $second = $aggregation->averageRepairTurnaroundDays();

        $this->assertSame($first, $second);
    }

    public function test_handles_large_customer_history_without_n_plus_one_incident_fetches(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $phone = '9000000199';

        for ($index = 0; $index < 15; $index++) {
            $order = Order::query()->create([
                'order_id' => 'RD-KNOW-BULK-'.$index,
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'customer_phone' => $phone,
                'status' => 'active',
                'created_by' => $agent->id,
            ]);

            Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => app(IncidentReferenceService::class)->generate(),
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => 'Bulk case '.$index,
                'description' => 'Case.',
                'status' => $index % 2 === 0 ? IncidentStatus::Closed : IncidentStatus::Open,
                'created_by' => $agent->id,
                'updated_by' => $agent->id,
            ]);
        }

        $current = Incident::query()->whereHas('order', fn ($q) => $q->where('customer_phone', $phone))->latest('id')->first();

        DB::enableQueryLog();
        $knowledge = app(KnowledgeEngine::class)->forIncident($current);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(15, $knowledge->customer->lifetimeRepairCount);
        $this->assertLessThan(30, $queryCount);
    }
}
