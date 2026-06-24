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
            ->assertSee('bi-telephone-fill', false)
            ->assertSee('data-bs-title="Call"', false)
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
        $response->assertSee('high-priority-dot', false);
        $response->assertSee('data-bs-title="High Priority"', false);
        $response->assertDontSee('>High Priority</span>', false);
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
            ->get(route('dashboard', ['filter' => 'completed']))
            ->assertOk()
            ->assertSee('Completed')
            ->assertSee('Transaction ID: TX123456')
            ->assertSee('25 Jun 2026, 10:45 AM')
            ->assertSee('Total turnaround:')
            ->assertSee('1 day 3 hours')
            ->assertSee('dashboard-case-row--completed', false);

        Carbon::setTestNow();
    }

    public function test_dashboard_defaults_to_pending_admin_filter(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $pendingOrder = Order::query()->create([
            'order_id' => 'RD-PENDING-1',
            'serial_number' => 'SN-PENDING-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $completedOrder = Order::query()->create([
            'order_id' => 'RD-COMPLETE-2',
            'serial_number' => 'SN-COMPLETE-2',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TX999',
            'completed_at' => now(),
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $pendingOrder->id,
            'reference_no' => 'SC-PENDING-1',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Pending case',
            'description' => 'Pending case.',
            'status' => 'open',
            'created_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $completedOrder->id,
            'reference_no' => 'SC-COMPLETE-2',
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Completed case',
            'description' => 'Completed case.',
            'status' => 'open',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('SC-PENDING-1')
            ->assertDontSee('SC-COMPLETE-2');
    }

    public function test_dashboard_high_priority_filter_shows_only_high_priority_cases(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-FILTER-HP',
            'serial_number' => 'SN-FILTER-HP',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-HP-ONLY',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'High priority',
            'description' => 'High priority.',
            'status' => 'open',
            'high_priority' => true,
            'created_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-NORMAL',
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Normal',
            'description' => 'Normal.',
            'status' => 'open',
            'high_priority' => false,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard', ['filter' => 'high_priority']))
            ->assertOk()
            ->assertSee('SC-HP-ONLY')
            ->assertDontSee('SC-NORMAL');
    }

    public function test_admin_dashboard_shows_bulk_selection_and_inline_transaction_controls(): void
    {
        $admin = User::factory()->create(['name' => 'Admin User']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-INLINE-1',
            'serial_number' => 'SN-INLINE-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-INLINE-1',
            'category' => 'General',
            'source' => IncidentSource::WhatsApp,
            'title' => 'Inline transaction test',
            'description' => 'Inline transaction test.',
            'status' => 'open',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-bulk-bar', false)
            ->assertSee('service-case-select', false)
            ->assertSee('Click to add')
            ->assertSee('data-inline-transaction="true"', false)
            ->assertSee('bi-whatsapp', false);
    }

    public function test_agent_dashboard_does_not_show_transaction_management_controls(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-AGENT-1',
            'serial_number' => 'SN-AGENT-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-AGENT-1',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Agent view',
            'description' => 'Agent view.',
            'status' => 'open',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('service-case-select', false)
            ->assertDontSee('Click to add')
            ->assertDontSee('data-bulk-bar', false);
    }

    public function test_admin_completed_row_shows_transaction_with_assign_tooltip(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-25 09:00:00'));

        $admin = User::factory()->create(['name' => 'Priya Sharma']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-ADMIN-COMPLETE',
            'serial_number' => 'SN-ADMIN-COMPLETE',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TX123456',
            'completed_at' => now(),
            'transaction_assigned_by' => $admin->id,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-ADMIN-COMPLETE',
            'category' => 'General',
            'source' => IncidentSource::Telegram,
            'title' => 'Completed admin row',
            'description' => 'Completed admin row.',
            'status' => 'open',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard', ['filter' => 'completed']))
            ->assertOk()
            ->assertSee('TX123456')
            ->assertSee('Assigned by Priya', false)
            ->assertSee('bi-check-circle-fill', false)
            ->assertDontSee('data-inline-transaction="true"', false);

        Carbon::setTestNow();
    }
}
