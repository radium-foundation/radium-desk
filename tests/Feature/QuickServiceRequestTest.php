<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickServiceRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_quick_create_creates_order_and_service_case_with_sc_reference(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $response = $this->actingAs($agent)->post(route('service-requests.quick.store'), [
            'order_id' => 'RD3421021',
            'serial_number' => 'SN001',
            'product' => 'MFS 110',
            'source' => IncidentSource::Call->value,
            'notes' => 'Customer reported device not powering on.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'incident-created');

        $this->assertDatabaseHas('orders', [
            'order_id' => 'RD3421021',
            'serial_number' => 'SN001',
            'product_name' => 'MFS 110',
        ]);

        $incident = Incident::query()->first();
        $this->assertNotNull($incident);
        $this->assertSame('SC-00001', $incident->reference_no);
        $this->assertSame(IncidentSource::Call, $incident->source);
    }

    public function test_quick_create_rejects_serial_mismatch_for_existing_order(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD3421021',
            'serial_number' => 'SN001',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->from(route('dashboard'))
            ->post(route('service-requests.quick.store'), [
                'order_id' => 'RD3421021',
                'serial_number' => 'SN999',
                'product' => 'MFS 110',
                'source' => IncidentSource::Email->value,
                'notes' => 'Customer reported device not powering on.',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('serial_number');
    }

    public function test_quick_create_adds_service_case_to_existing_order_when_serial_matches(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD3421021',
            'serial_number' => 'SN001',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)->post(route('service-requests.quick.store'), [
            'order_id' => 'RD3421021',
            'serial_number' => 'SN001',
            'product' => 'MFS 110',
            'source' => IncidentSource::WhatsApp->value,
            'notes' => 'Follow-up service case for same order.',
        ])->assertRedirect();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Incident::query()->where('order_id', $order->id)->count());
    }
}
