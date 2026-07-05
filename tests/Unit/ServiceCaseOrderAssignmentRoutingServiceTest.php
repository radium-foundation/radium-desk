<?php

namespace Tests\Unit;

use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\ServiceCaseOrderAssignmentRoutingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCaseOrderAssignmentRoutingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'service_case_assignment.hardware_order.assignee_email' => 'sumit@radiumbox.com',
        ]);
    }

    public function test_matches_hardware_order_id_prefix(): void
    {
        $service = app(ServiceCaseOrderAssignmentRoutingService::class);

        $this->assertTrue($service->matches($this->incidentWithOrderId('RDE253851')));
        $this->assertTrue($service->matches($this->incidentWithOrderId('rde123')));
        $this->assertFalse($service->matches($this->incidentWithOrderId('RD-253851')));
    }

    public function test_resolve_assignee_returns_active_hardware_team_user(): void
    {
        $sumit = User::factory()->create([
            'email' => 'sumit@radiumbox.com',
            'is_active' => true,
        ]);
        $sumit->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        $assignee = app(ServiceCaseOrderAssignmentRoutingService::class)
            ->resolveAssignee($this->incidentWithOrderId('RDE100'));

        $this->assertTrue($assignee?->is($sumit));
    }

    public function test_resolve_assignee_returns_null_for_non_rde_order(): void
    {
        User::factory()->create([
            'email' => 'sumit@radiumbox.com',
            'is_active' => true,
        ])->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        $assignee = app(ServiceCaseOrderAssignmentRoutingService::class)
            ->resolveAssignee($this->incidentWithOrderId('RD-100'));

        $this->assertNull($assignee);
    }

    private function incidentWithOrderId(string $orderId): Incident
    {
        $actor = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => $orderId,
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-ROUTING-'.uniqid(),
            'category' => 'General',
            'source' => 'cashfree',
            'title' => 'Routing unit test',
            'description' => 'Routing unit test.',
            'status' => 'open',
            'created_by' => $actor->id,
        ]);
    }
}
