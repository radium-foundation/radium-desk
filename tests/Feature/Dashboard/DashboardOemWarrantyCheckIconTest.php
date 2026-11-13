<?php

namespace Tests\Feature\Dashboard;

use App\Enums\IncidentSource;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardOemWarrantyCheckIconTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->withoutVite();
    }

    public function test_dashboard_shows_oem_check_icon_after_passing_serial_for_mfs110(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-OEM-ICON-1',
            'serial_number' => '6540662',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'OEM icon test',
            'description' => 'OEM icon test.',
            'status' => 'open',
            'created_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-oem-warranty-check', false)
            ->assertSee('data-oem-vendor="mantra"', false)
            ->assertSee('Check OEM warranty / renewal', false)
            ->assertDontSee('data-oem-serial=', false);
    }

    public function test_dashboard_hides_oem_check_for_unsupported_fm220(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-OEM-ICON-2',
            'serial_number' => '6540663',
            'product_name' => 'FM 220',
            'device_model' => 'FM 220',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'OEM icon hidden test',
            'description' => 'OEM icon hidden test.',
            'status' => 'open',
            'created_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('data-oem-warranty-check', false);
    }
}
