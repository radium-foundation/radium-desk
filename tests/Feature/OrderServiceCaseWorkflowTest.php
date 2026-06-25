<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderServiceCaseWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $dayAdmin = User::factory()->create(['email' => 'day-admin@test.com']);
        $dayAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        app(\App\Services\SettingService::class)->setMany([
            'assignment.timezone' => config('app.timezone'),
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $dayAdmin->id,
            'assignment.night_shift_admin_user_id' => '',
            'assignment.fallback_admin_1_user_id' => '',
            'assignment.fallback_admin_2_user_id' => '',
        ]);
    }

    public function test_order_page_shows_summary_history_and_active_banner(): void
    {
        $agent = User::factory()->create(['name' => 'Avinash Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD3419607',
            'serial_number' => 'SN-HUB-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Jane Customer',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-00001',
            'category' => 'General',
            'source' => IncidentSource::Call->value,
            'title' => 'Activation Issue',
            'description' => 'Activation failed',
            'status' => IncidentStatus::Closed->value,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $agent->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-00002',
            'category' => 'General',
            'source' => IncidentSource::Call->value,
            'title' => 'Remote Support',
            'description' => 'Remote session required',
            'status' => IncidentStatus::InProgress->value,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $agent->id,
        ]);

        $response = $this->actingAs($agent)->get(route('orders.show', $order));

        $response->assertOk()
            ->assertSee('RD3419607')
            ->assertSee('Jane Customer')
            ->assertSee('Service Case History')
            ->assertSee('SC00001')
            ->assertSee('SC00002')
            ->assertSee('Activation Issue')
            ->assertSee('Remote Support')
            ->assertSee(config('ui.service_case.active_banner_heading'))
            ->assertSee('Avinash')
            ->assertSee(config('ui.service_case.create_new_action'))
            ->assertSee(route('orders.service-cases.create', $order), false);
    }

    public function test_create_service_case_from_order_redirects_to_new_service_case(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-CREATE-1',
            'serial_number' => 'SN-CREATE-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $response = $this->actingAs($agent)->post(route('orders.service-cases.store', $order), [
            'source' => IncidentSource::Call->value,
            'notes' => 'New complaint after closure.',
            'high_priority' => '1',
        ]);

        $incident = Incident::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($incident);
        $this->assertSame('SC00001', $incident->reference_no);

        $response->assertRedirect(route('incidents.show', $incident));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Incident::query()->where('order_id', $order->id)->count());
    }

    public function test_create_service_case_page_shows_previous_cases_and_active_banner(): void
    {
        $agent = User::factory()->create(['name' => 'Avinash Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-CREATE-PAGE',
            'serial_number' => 'SN-CREATE-PAGE',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $active = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-00010',
            'category' => 'General',
            'source' => IncidentSource::Call->value,
            'title' => 'Open issue',
            'description' => 'Still open',
            'status' => IncidentStatus::Open->value,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $agent->id,
        ]);

        $response = $this->actingAs($agent)->get(route('orders.service-cases.create', $order));

        $response->assertOk()
            ->assertSee(config('ui.service_case.previous_cases_heading'))
            ->assertSee('SC00010')
            ->assertSee(config('ui.service_case.active_banner_heading'))
            ->assertSee(route('incidents.show', $active), false)
            ->assertSee(config('ui.service_case.continue_creating_action'));
    }

    public function test_multiple_service_cases_belong_to_one_order(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-MULTI-1',
            'serial_number' => 'SN-MULTI-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)->post(route('orders.service-cases.store', $order), [
            'source' => IncidentSource::Call->value,
            'notes' => 'First issue',
        ])->assertRedirect();

        $this->actingAs($agent)->post(route('orders.service-cases.store', $order), [
            'source' => IncidentSource::Email->value,
            'notes' => 'Second issue',
        ])->assertRedirect();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(2, Incident::query()->where('order_id', $order->id)->count());
    }
}
