<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardServiceCasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_dashboard_shows_service_case_grid_columns(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:35:00'));

        $agent = User::factory()->create(['name' => 'Ravi']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD3421021',
            'serial_number' => 'SN001',
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
            'title' => 'Service request — MFS110',
            'description' => 'Initial service case logged from dashboard.',
            'status' => 'open',
            'created_by' => $agent->id,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-24 20:47:00'));

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Recent Service Cases')
            ->assertSee('SC-00001')
            ->assertSee('RD3421021')
            ->assertSee('SN001')
            ->assertSee('MFS 110')
            ->assertSee('Call')
            ->assertSee('Pending Admin')
            ->assertSee('Waiting for Transaction ID')
            ->assertSee('Created:')
            ->assertSee('24 Jun 2026, 02:35 PM')
            ->assertSee('Pending for:')
            ->assertSee('6 hours 12 minutes')
            ->assertSee('Last Updated')
            ->assertSee('Ravi');

        Carbon::setTestNow();
    }

    public function test_dashboard_shows_first_name_only_for_logged_by(): void
    {
        $agent = User::factory()->create(['name' => 'Ravi Kumar']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-NAME-001',
            'serial_number' => 'SN-NAME-001',
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
            'title' => 'Name display test',
            'description' => 'Testing first name display.',
            'status' => 'open',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('>Ravi</td>', false);
    }

    public function test_dashboard_sorts_high_priority_service_cases_first(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-SORT-001',
            'serial_number' => 'SN-SORT-001',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-00002',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Newer normal case',
            'description' => 'Normal priority case.',
            'status' => 'open',
            'high_priority' => false,
            'created_by' => $agent->id,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-00001',
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Older high priority case',
            'description' => 'High priority case.',
            'status' => 'open',
            'high_priority' => true,
            'created_by' => $agent->id,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($agent)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSeeInOrder(['SC-00001', 'SC-00002']);
        $response->assertSee('High Priority');
    }

    public function test_dashboard_completed_tooltip_shows_transaction_and_turnaround(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 07:45:00'));

        $agent = User::factory()->create(['name' => 'Ravi']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-COMPLETE-1',
            'serial_number' => 'SN-COMPLETE-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Completed service case',
            'description' => 'Completed service case for tooltip test.',
            'status' => 'open',
            'created_by' => $agent->id,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-25 10:45:00'));

        $order->update([
            'transaction_id' => 'TX123456',
            'completed_at' => now(),
        ]);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Completed')
            ->assertSee('Transaction ID: TX123456')
            ->assertSee('25 Jun 2026, 10:45 AM')
            ->assertSee('Total turnaround:')
            ->assertSee('1 day 3 hours');

        Carbon::setTestNow();
    }
}
