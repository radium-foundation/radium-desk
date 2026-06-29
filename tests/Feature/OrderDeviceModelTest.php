<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderDeviceModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DeviceModelSeeder::class);
    }

    public function test_agent_can_assign_device_model_from_dashboard(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = Order::query()->create([
            'order_id' => 'RD-MODEL-001',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-MODEL-001',
            'category' => 'General',
            'source' => 'cashfree',
            'title' => 'Cashfree activation',
            'description' => 'Awaiting device model.',
            'status' => IncidentStatus::InProgress->value,
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->postJson(route('orders.device-model.store', $order), [
                'device_model_id' => $deviceModel->id,
                'incident_id' => $incident->id,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Device model assigned successfully.')
            ->assertJsonPath('incident_id', $incident->id);

        $order->refresh();
        $this->assertSame($deviceModel->id, $order->device_model_id);
        $this->assertSame('MFS110', $order->device_model);
        $this->assertSame('MFS110', $order->product_name);
        $this->assertNotNull($order->device_model_assigned_at);
        $this->assertSame($agent->id, $order->device_model_assigned_by_user_id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'device-model.assigned',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'user_id' => $agent->id,
        ]);
    }

    public function test_admin_can_bulk_assign_device_model(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $deviceModel = DeviceModel::query()->where('name', 'L1')->firstOrFail();

        $orders = collect(['RD-BULK-1', 'RD-BULK-2'])->map(function (string $orderId) use ($admin) {
            return Order::query()->create([
                'order_id' => $orderId,
                'serial_number' => null,
                'product_name' => null,
                'device_model' => null,
                'status' => 'active',
                'created_by' => $admin->id,
            ]);
        });

        $incidents = $orders->map(function (Order $order, int $index) use ($admin) {
            return Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => 'SC-BULK-'.($index + 1),
                'category' => 'General',
                'source' => 'cashfree',
                'title' => 'Cashfree activation',
                'description' => 'Awaiting device model.',
                'status' => IncidentStatus::InProgress->value,
                'created_by' => $admin->id,
            ]);
        });

        $this->actingAs($admin)
            ->postJson(route('dashboard.workspace.batch-device-model'), [
                'incident_ids' => $incidents->pluck('id')->all(),
                'device_model_id' => $deviceModel->id,
                'workspace_context' => 'dashboard',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        foreach ($orders as $order) {
            $order->refresh();
            $this->assertSame($deviceModel->id, $order->device_model_id);
            $this->assertSame('L1', $order->device_model);

            $this->assertDatabaseHas('audit_logs', [
                'event' => 'device-model.bulk-assigned',
                'auditable_type' => $order->getMorphClass(),
                'auditable_id' => $order->id,
                'user_id' => $admin->id,
            ]);
        }
    }

    public function test_dashboard_row_shows_plus_when_device_model_missing(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-MODEL-UI',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-MODEL-UI',
            'category' => 'General',
            'source' => 'cashfree',
            'title' => 'Cashfree activation',
            'description' => 'Awaiting device model.',
            'status' => IncidentStatus::InProgress->value,
            'created_by' => $agent->id,
        ]);

        $response = $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.row', $incident));

        $response->assertOk();
        $html = $response->json('html');
        $this->assertStringContainsString('data-device-model-cell="true"', $html);
        $this->assertStringContainsString('Assign model', $html);
    }

    public function test_dashboard_row_shows_model_name_without_plus_when_assigned(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $deviceModel = DeviceModel::query()->where('name', 'MSO E3')->firstOrFail();

        $order = Order::query()->create([
            'order_id' => 'RD-MODEL-DONE',
            'serial_number' => null,
            'product_name' => 'MSO E3',
            'device_model' => 'MSO E3',
            'device_model_id' => $deviceModel->id,
            'device_model_assigned_at' => now(),
            'device_model_assigned_by_user_id' => $agent->id,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-MODEL-DONE',
            'category' => 'General',
            'source' => 'cashfree',
            'title' => 'Cashfree activation',
            'description' => 'Model assigned.',
            'status' => IncidentStatus::InProgress->value,
            'created_by' => $agent->id,
        ]);

        $response = $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.row', $incident));

        $response->assertOk();
        $html = $response->json('html');
        $this->assertStringContainsString('MSO E3', $html);
        $this->assertStringNotContainsString('data-device-model-cell="true"', $html);
    }

    public function test_dashboard_row_shows_short_model_with_full_name_tooltip(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-MODEL-LONG',
            'serial_number' => null,
            'product_name' => 'MFS 110 Refrigerator Cold Storage Unit',
            'device_model' => 'MFS 110 Refrigerator Cold Storage Unit',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-MODEL-LONG',
            'category' => 'General',
            'source' => 'cashfree',
            'title' => 'Long model name',
            'description' => 'Testing short model display.',
            'status' => IncidentStatus::InProgress->value,
            'created_by' => $agent->id,
        ]);

        $response = $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.row', $incident));

        $response->assertOk();
        $html = $response->json('html');
        $this->assertStringContainsString('>MFS 110<', $html);
        $this->assertStringContainsString(
            'data-bs-title="MFS 110 Refrigerator Cold Storage Unit"',
            $html,
        );
        $this->assertStringNotContainsString('>MFS 110 Refrigerator', $html);
    }

    public function test_order_show_displays_device_model_assignment_metadata(): void
    {
        $agent = User::factory()->create(['name' => 'Gaurav Patel']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $deviceModel = DeviceModel::query()->where('name', 'Morpho 1300')->firstOrFail();
        $assignedAt = now()->subHour();

        $order = Order::query()->create([
            'order_id' => 'RD-MODEL-SHOW',
            'serial_number' => null,
            'product_name' => 'Morpho 1300',
            'device_model' => 'Morpho 1300',
            'device_model_id' => $deviceModel->id,
            'device_model_assigned_at' => $assignedAt,
            'device_model_assigned_by_user_id' => $agent->id,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Model', false)
            ->assertSee('Assigned By', false)
            ->assertSee('Assigned At', false);
    }

    public function test_dashboard_shows_bulk_device_model_action_for_admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Assign Model', false);
    }
}
