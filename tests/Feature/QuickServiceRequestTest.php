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

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('status', 'service-case-created');
        $response->assertSessionHas('service_case_reference', 'SC-00001');
        $response->assertSessionHas('reopen_quick_create', true);

        $this->assertDatabaseHas('orders', [
            'order_id' => 'RD3421021',
            'serial_number' => 'SN001',
            'product_name' => 'MFS 110',
        ]);

        $incident = Incident::query()->first();
        $this->assertNotNull($incident);
        $this->assertSame('SC-00001', $incident->reference_no);
        $this->assertSame(IncidentSource::Call, $incident->source);
        $this->assertFalse($incident->high_priority);
    }

    public function test_quick_create_allows_optional_comment_and_high_priority(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)->post(route('service-requests.quick.store'), [
            'order_id' => 'RD-HP-001',
            'serial_number' => 'SN-HP-001',
            'product' => 'MFS 110',
            'source' => IncidentSource::Call->value,
            'high_priority' => '1',
        ])->assertRedirect(route('dashboard'));

        $incident = Incident::query()->first();
        $this->assertNotNull($incident);
        $this->assertTrue($incident->high_priority);
        $this->assertSame('', $incident->description);
    }

    public function test_dashboard_reopens_quick_create_modal_after_successful_create(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->withSession([
            'status' => 'service-case-created',
            'service_case_reference' => 'SC-00001',
            'reopen_quick_create' => true,
        ])
            ->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-show-on-load="true"', false)
            ->assertSee('data-reset-on-show="true"', false)
            ->assertSee('Service Case SC-00001 created successfully.');
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
        ])->assertRedirect(route('dashboard'));

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Incident::query()->where('order_id', $order->id)->count());
    }
}
