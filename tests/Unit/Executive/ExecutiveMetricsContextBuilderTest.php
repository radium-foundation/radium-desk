<?php

namespace Tests\Unit\Executive;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Executive\ExecutiveMetricsContextBuilder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExecutiveMetricsContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_critical_cases_query_accepts_high_priority_column(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-EXEC-HP-1',
            'serial_number' => '7881002',
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-EXEC-HP-1',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Critical case',
            'description' => 'Critical case',
            'status' => IncidentStatus::Open,
            'high_priority' => true,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $context = app(ExecutiveMetricsContextBuilder::class)->build();

        $this->assertSame(1, $context->openCases);
        $this->assertSame(1, $context->criticalCases);
    }
}
