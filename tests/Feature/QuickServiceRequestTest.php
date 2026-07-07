<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\NewContactIntent;
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

    public function test_existing_device_service_intake_creates_verification_needed_case(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $response = $this->actingAs($agent)->post(route('service-requests.quick.store'), [
            'action' => 'new_contact',
            'intent' => NewContactIntent::ExistingDeviceService->value,
            'phone' => '9876543210',
            'serial_number' => '7881953',
            'product' => 'MFS 110',
            'source' => IncidentSource::Call->value,
            'notes' => 'Customer reported device not powering on.',
        ]);

        $incident = Incident::query()->first();
        $this->assertNotNull($incident);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('status', 'service-case-created');
        $response->assertSessionHas('service_case_reference', 'SC00001');
        $response->assertSessionHas('reopen_quick_create', true);

        $this->assertTrue(Order::isInquiryOrderId($incident->order->order_id));
        $this->assertSame('7881953', $incident->order->serial_number);
        $this->assertSame('Service', $incident->category);
        $this->assertSame(IncidentSource::Call, $incident->source);
    }

    public function test_quick_create_requires_comment_and_allows_high_priority(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)->post(route('service-requests.quick.store'), [
            'action' => 'new_contact',
            'intent' => NewContactIntent::GeneralSupport->value,
            'phone' => '9876543211',
            'source' => IncidentSource::Call->value,
            'notes' => 'Customer reported intermittent connectivity.',
            'high_priority' => '1',
        ])->assertRedirect();

        $incident = Incident::query()->first();
        $this->assertNotNull($incident);
        $this->assertTrue($incident->high_priority);
        $this->assertSame('Customer reported intermittent connectivity.', $incident->description);
    }

    public function test_quick_create_rejects_missing_comment(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->from(route('dashboard'))
            ->post(route('service-requests.quick.store'), [
                'action' => 'new_contact',
                'intent' => NewContactIntent::GeneralSupport->value,
                'phone' => '9876543212',
                'source' => IncidentSource::Call->value,
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('notes');
    }

    public function test_dashboard_reopens_quick_create_modal_after_successful_create(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->withSession([
            'status' => 'service-case-created',
            'service_case_reference' => 'SC00001',
            'reopen_quick_create' => true,
        ])
            ->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-show-on-load="true"', false)
            ->assertSee('data-reset-on-show="true"', false)
            ->assertSee('data-reopen-quick-create="true"', false)
            ->assertSee('Service Case SC00001 created successfully.');
    }

    public function test_admin_dashboard_reopens_quick_create_modal_after_successful_create(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->withSession([
            'status' => 'service-case-created',
            'service_case_reference' => 'SC00001',
            'reopen_quick_create' => true,
        ])
            ->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-show-on-load="true"', false)
            ->assertSee('data-reset-on-show="true"', false)
            ->assertSee('data-reopen-quick-create="true"', false);
    }

    public function test_superadmin_dashboard_reopens_quick_create_modal_after_successful_create(): void
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->withSession([
            'status' => 'service-case-created',
            'service_case_reference' => 'SC00001',
            'reopen_quick_create' => true,
        ])
            ->actingAs($superadmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-reopen-quick-create="true"', false)
            ->assertSee('data-reset-on-show="true"', false);
    }

    public function test_existing_order_intake_rejects_serial_mismatch(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD3421021',
            'serial_number' => '7881953',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->from(route('dashboard'))
            ->post(route('service-requests.quick.store'), [
                'action' => 'existing_order',
                'matched_order_id' => $order->id,
                'serial_number' => '9999999',
                'source' => IncidentSource::Email->value,
                'notes' => 'Customer reported a different serial number.',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('serial_number');

        $this->assertSame(0, Incident::query()->where('order_id', $order->id)->count());
    }

    public function test_existing_order_open_only_redirects_without_creating_service_case(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD3421021',
            'serial_number' => '7881953',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)->post(route('service-requests.quick.store'), [
            'action' => 'existing_order',
            'matched_order_id' => $order->id,
            'open_only' => '1',
            'serial_number' => '7881953',
            'source' => IncidentSource::WhatsApp->value,
            'notes' => 'Open existing order without creating a case.',
        ])
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('status', 'order-found');

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(0, Incident::query()->where('order_id', $order->id)->count());
    }
}
