<?php

namespace Tests\Unit\AI;

use App\Data\AI\AIContextBuildSnapshot;
use App\Data\AI\AIContextDTO;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\AI\IncidentAIContextBuilder;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncidentAIContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_builds_context_with_customer_order_and_incident_details(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-AI-CTX',
            'serial_number' => 'SN-AI-CTX',
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => 'TXN-AI',
            'customer_name' => 'Context Customer',
            'customer_email' => 'context@example.com',
            'customer_phone' => '9000000001',
            'payment_amount' => 1500,
            'payment_date' => now(),
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'Hardware',
            'source' => IncidentSource::Call,
            'title' => 'Device not working',
            'description' => 'Customer reported startup failure.',
            'status' => IncidentStatus::Open,
            'high_priority' => true,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $context = app(IncidentAIContextBuilder::class)->build($incident);

        $this->assertInstanceOf(AIContextDTO::class, $context);
        $this->assertSame($incident->id, $context->incidentId);
        $this->assertSame(1, $context->customerIntelligence->lifetimeOrderCount);
        $this->assertSame(1500.0, $context->businessIntelligence->revenueFromCustomer);
        $this->assertNotEmpty($context->riskIndicators);
    }

    public function test_detects_repeat_repair_on_same_title(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-AI-REPEAT',
            'serial_number' => 'SN-REPEAT',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9000000099',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'Hardware',
            'source' => IncidentSource::Call,
            'title' => 'Screen not working',
            'description' => 'Closed case.',
            'status' => IncidentStatus::Closed,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $current = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'Hardware',
            'source' => IncidentSource::Call,
            'title' => 'Screen not working',
            'description' => 'Open case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $context = app(IncidentAIContextBuilder::class)->build($current);

        $this->assertTrue($context->customerIntelligence->repeatIssueDetected);
        $this->assertStringContainsString('Screen not working', (string) $context->customerIntelligence->repeatIssueSummary);
    }

    public function test_reuses_snapshot_to_avoid_duplicate_customer_summary_query(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-AI-SNAPSHOT',
            'serial_number' => 'SN-SNAPSHOT',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9000000088',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Snapshot case',
            'description' => 'Snapshot case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $snapshot = new AIContextBuildSnapshot(
            customerSummary: [
                'total_orders' => 1,
                'total_devices' => 1,
                'open_cases' => 1,
                'closed_cases' => 0,
            ],
        );

        DB::enableQueryLog();
        $context = app(IncidentAIContextBuilder::class)->build($incident, $snapshot);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $customerSummaryQueries = collect($queries)->filter(
            fn (array $query) => str_contains(strtolower($query['query']), 'from "orders"')
                && str_contains(strtolower($query['query']), 'customer_phone'),
        )->count();

        $this->assertSame(1, $context->customerSummary['total_orders']);
        $this->assertLessThanOrEqual(1, $customerSummaryQueries);
    }
}
