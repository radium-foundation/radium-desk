<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\ServiceCaseActivityTimelineService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerialValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'automation.display_name' => 'Ira',
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);
    }

    public function test_dashboard_serial_assignment_rejects_invalid_mfs110_serial(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-IRA-INVALID',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->postJson(route('orders.serial.store', $order), [
                'serial_number' => 'ABC123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['serial_number']);
    }

    public function test_dashboard_serial_assignment_accepts_valid_mfs110_serial(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-IRA-VALID',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->postJson(route('orders.serial.store', $order), [
                'serial_number' => '7881953',
            ])
            ->assertOk();

        $this->assertSame('7881953', $order->fresh()->serial_number);
    }

    public function test_mso_e3_serial_correction_records_ira_audit_and_timeline_entry(): void
    {
        $systemUser = User::factory()->create([
            'email' => 'superadmin@radium.local',
            'name' => 'Super Admin',
            'first_name' => 'Super',
            'is_active' => true,
        ]);
        $systemUser->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-IRA-MSO',
            'serial_number' => null,
            'product_name' => 'MSO E3',
            'device_model' => 'MSO E3',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-IRA-MSO',
            'category' => 'General',
            'source' => 'call',
            'title' => 'MSO E3 activation',
            'description' => 'Awaiting serial number.',
            'status' => IncidentStatus::InProgress->value,
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->postJson(route('orders.serial.store', $order), [
                'serial_number' => '2423L016089',
                'incident_id' => $incident->id,
            ])
            ->assertOk();

        $order->refresh();
        $this->assertSame('2423I016089', $order->serial_number);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'serial.corrected_by_ira',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
        ]);

        $timeline = app(ServiceCaseActivityTimelineService::class)->forIncident($incident->fresh());
        $iraEntry = $timeline->first(fn ($entry) => $entry->title === 'Corrected by IRA');

        $this->assertNotNull($iraEntry);
        $this->assertSame('Ira', $iraEntry->actor->displayName);
        $this->assertStringContainsString('2423L016089', (string) $iraEntry->body);
        $this->assertStringContainsString('2423I016089', (string) $iraEntry->body);
    }

    public function test_quick_create_rejects_invalid_serial_for_product(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        config(['service_case_assignment.automation_grace_period_enabled' => false]);

        $this->actingAs($agent)
            ->post(route('service-requests.quick.store'), [
                'action' => 'new_contact',
                'intent' => \App\Enums\NewContactIntent::ExistingDeviceService->value,
                'serial_number' => 'NOT-VALID',
                'product' => 'MFS 110',
                'source' => IncidentSource::Call->value,
                'notes' => 'Invalid serial validation test.',
            ])
            ->assertSessionHasErrors(['serial_number']);
    }
}
