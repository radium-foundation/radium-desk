<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickServiceRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $dayAdmin = User::factory()->create(['email' => 'day-admin@test.com']);
        $dayAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $nightAdmin = User::factory()->create(['email' => 'night-admin@test.com']);
        $nightAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        app(\App\Services\SettingService::class)->setMany([
            'assignment.timezone' => config('app.timezone'),
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $dayAdmin->id,
            'assignment.night_shift_admin_user_id' => (string) $nightAdmin->id,
            'assignment.fallback_admin_1_user_id' => '',
            'assignment.fallback_admin_2_user_id' => '',
        ]);
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

        $incident = Incident::query()->first();
        $this->assertNotNull($incident);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('status', 'service-case-created');
        $response->assertSessionHas('service_case_reference', 'SC00001');

        $this->assertDatabaseHas('orders', [
            'order_id' => 'RD3421021',
            'serial_number' => 'SN001',
            'product_name' => 'MFS 110',
        ]);

        $this->assertSame('SC00001', $incident->reference_no);
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
        ])->assertRedirect();

        $incident = Incident::query()->first();
        $this->assertNotNull($incident);
        $this->assertTrue($incident->high_priority);
        $this->assertSame('', $incident->description);
    }

    public function test_quick_create_rejects_serial_mismatch_for_existing_order(): void
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

        $this->assertSame(0, Incident::query()->where('order_id', $order->id)->count());
    }

    public function test_quick_create_redirects_to_order_page_for_existing_order_without_creating_service_case(): void
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
        ])
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('status', 'order-found');

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(0, Incident::query()->where('order_id', $order->id)->count());
    }
}
