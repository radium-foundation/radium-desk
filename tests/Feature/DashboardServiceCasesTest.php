<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createAssignedIncident(User $agent, array $attributes = []): Incident
    {
        $order = Order::query()->create([
            'order_id' => $attributes['order_id'] ?? 'RD-AGENT-'.uniqid(),
            'serial_number' => $attributes['serial_number'] ?? 'SN-AGENT-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $attributes['reference_no'] ?? app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => $attributes['source'] ?? IncidentSource::Call,
            'title' => $attributes['title'] ?? 'Agent dashboard case',
            'description' => $attributes['description'] ?? 'Agent dashboard case.',
            'status' => $attributes['status'] ?? 'open',
            'high_priority' => $attributes['high_priority'] ?? false,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
            ...$attributes,
        ]);
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
            'assigned_to_user_id' => $agent->id,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-24 20:47:00'));

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Active')
            ->assertSee('SC00001')
            ->assertSee(route('orders.show', $order), false)
            ->assertSee('RD3421021')
            ->assertSee('SN001')
            ->assertSee('MFS 110')
            ->assertSee('bi-telephone-fill', false)
            ->assertSee('data-bs-title="Call"', false)
            ->assertSee('data-bs-title="Pending Admin"', false)
            ->assertSee('aria-label="Pending Admin"', false)
            ->assertSee('Waiting for Service Reference')
            ->assertSee('dashboard-premium-tooltip__label', false)
            ->assertSee('24 Jun 2026, 02:35 PM')
            ->assertSee('6 hours 12 minutes')
            ->assertSee('Within SLA')
            ->assertSee('Updated')
            ->assertSee('Ravi');

        Carbon::setTestNow();
    }

    public function test_dashboard_shows_user_avatar_with_tooltip_for_logged_by(): void
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
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('dashboard-people-avatars', false)
            ->assertSee('dashboard-u-avatar', false)
            ->assertSee('aria-label="Logged by: Ravi Kumar"', false)
            ->assertSee('aria-label="Assigned To: Ravi Kumar"', false)
            ->assertSee('data-bs-title="Ravi Kumar"', false);
    }

    public function test_dashboard_grid_renders_compact_serial_validation_indicator(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-VALIDATION-1',
            'serial_number' => 'SN-VALIDATION-1',
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
            'title' => 'Validation indicator test',
            'description' => 'Validation indicator test.',
            'status' => 'open',
            'created_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('SN-VALIDATION-1')
            ->assertSee('serial-validation-indicator', false)
            ->assertSee('data-bs-title', false);
    }

    public function test_dashboard_grid_rows_open_customer360_via_click(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-ROW-ACTION-1',
            'serial_number' => 'SN-ROW-ACTION-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Row click accessibility test',
            'description' => 'Row click accessibility test.',
            'status' => 'open',
            'created_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('dashboard-case-row--clickable', false)
            ->assertSee('data-incident-id="'.$incident->id.'"', false)
            ->assertDontSee('dashboard-row-actions', false)
            ->assertDontSee('data-c360-open-more-menu', false)
            ->assertDontSee('data-workspace-trigger="remark"', false);
    }

    public function test_dashboard_sorts_high_priority_service_cases_first(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 18:00:00'));

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

        $normalIncident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-00002',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Newer normal case',
            'description' => 'Normal priority case.',
            'status' => 'open',
            'high_priority' => false,
            'created_by' => $agent->id,
        ]);
        $normalIncident->forceFill([
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ])->saveQuietly();

        $highPriorityIncident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-00001',
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Older high priority case',
            'description' => 'High priority case.',
            'status' => 'open',
            'high_priority' => true,
            'created_by' => $agent->id,
        ]);
        $highPriorityIncident->forceFill([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ])->saveQuietly();

        $sorted = app(\App\Services\DashboardService::class)->recentServiceCases('pending_admin', 10);

        $this->assertSame(['SC-00001', 'SC-00002'], $sorted->pluck('reference_no')->all());

        Carbon::setTestNow();
    }

    public function test_dashboard_completed_tooltip_shows_transaction_and_turnaround(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 07:45:00'));

        $admin = User::factory()->create(['name' => 'Ravi']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-COMPLETE-1',
            'serial_number' => 'SN-COMPLETE-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Completed service case',
            'description' => 'Completed service case for tooltip test.',
            'status' => 'open',
            'created_by' => $admin->id,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-25 10:45:00'));

        $order->update([
            'transaction_id' => 'TX123456',
            'completed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard', ['filter' => 'completed']))
            ->assertOk()
            ->assertSee('Completed')
            ->assertSee('TX123456')
            ->assertSee('25 Jun 2026, 10:45 AM')
            ->assertSee('Total turnaround')
            ->assertSee('1 day 3 hours')
            ->assertSee('dashboard-case-row--completed', false);

        Carbon::setTestNow();
    }

    public function test_dashboard_defaults_to_ready_queue_filter_aligned_with_queue(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $readyCase = $this->createAdminOpenCase(
            $admin,
            'RD-READY-DEFAULT',
            assignedTo: $admin,
        );

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
            ->assertSee('data-live-queue="action_required"', false)
            ->assertSee('data-live-filter="action_required"', false)
            ->assertSee('RD-READY-DEFAULT')
            ->assertDontSee('SC-COMPLETE-2');

        $this->assertSame($readyCase->id, $readyCase->fresh()->id);
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

    public function test_dashboard_high_priority_filter_excludes_closed_cases(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-FILTER-HP-CLOSED',
            'serial_number' => 'SN-FILTER-HP-CLOSED',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-HP-ACTIVE',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Active high priority',
            'description' => 'Active high priority.',
            'status' => IncidentStatus::Open->value,
            'high_priority' => true,
            'created_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-HP-CLOSED',
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Closed high priority',
            'description' => 'Closed high priority.',
            'status' => IncidentStatus::Closed->value,
            'high_priority' => true,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard', ['filter' => 'high_priority']))
            ->assertOk()
            ->assertSee('SC-HP-ACTIVE')
            ->assertDontSee('SC-HP-CLOSED');
    }

    public function test_dashboard_needs_attention_filter_shows_only_active_cases_with_missing_serial(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $missingSerialOrder = Order::query()->create([
            'order_id' => 'RD-NA-MISSING',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $whitespaceSerialOrder = Order::query()->create([
            'order_id' => 'RD-NA-BLANK',
            'serial_number' => '   ',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $presentSerialOrder = Order::query()->create([
            'order_id' => 'RD-NA-PRESENT',
            'serial_number' => 'SN-NA-PRESENT',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $missingSerialOrder->id,
            'reference_no' => 'SC-NA-MISSING',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Missing serial',
            'description' => 'Missing serial.',
            'status' => IncidentStatus::Open->value,
            'created_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $whitespaceSerialOrder->id,
            'reference_no' => 'SC-NA-BLANK',
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Whitespace serial',
            'description' => 'Whitespace serial.',
            'status' => IncidentStatus::InProgress->value,
            'created_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $presentSerialOrder->id,
            'reference_no' => 'SC-NA-PRESENT',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Has serial',
            'description' => 'Has serial.',
            'status' => IncidentStatus::Open->value,
            'created_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $missingSerialOrder->id,
            'reference_no' => 'SC-NA-CLOSED',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Closed missing serial',
            'description' => 'Closed missing serial.',
            'status' => IncidentStatus::Closed->value,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard', ['filter' => 'needs_attention']))
            ->assertOk()
            ->assertSee('Needs Attention')
            ->assertSee('SC-NA-MISSING')
            ->assertSee('SC-NA-BLANK')
            ->assertDontSee('SC-NA-PRESENT')
            ->assertDontSee('SC-NA-CLOSED');
    }

    public function test_dashboard_needs_attention_filter_count_matches_matching_cases(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        foreach (['SC-NA-COUNT-1', 'SC-NA-COUNT-2'] as $referenceNo) {
            $order = Order::query()->create([
                'order_id' => 'RD-'.str_replace('-', '', $referenceNo),
                'serial_number' => null,
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
                'created_by' => $admin->id,
            ]);

            Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => $referenceNo,
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => 'Missing serial count',
                'description' => 'Missing serial count.',
                'status' => IncidentStatus::Open->value,
                'created_by' => $admin->id,
            ]);
        }

        $presentOrder = Order::query()->create([
            'order_id' => 'RD-NACOUNTPRESENT',
            'serial_number' => 'SN-NA-COUNT-PRESENT',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $presentOrder->id,
            'reference_no' => 'SC-NA-COUNT-3',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Has serial count',
            'description' => 'Has serial count.',
            'status' => IncidentStatus::Open->value,
            'created_by' => $admin->id,
        ]);

        $counts = app(\App\Services\DashboardService::class)->serviceCaseFilterCounts(null, $admin);

        $this->assertSame(2, $counts['needs_attention']);

        $this->actingAs($admin)
            ->get(route('dashboard', ['filter' => 'needs_attention']))
            ->assertOk()
            ->assertSee('data-dashboard-case-filter-count="needs_attention"', false)
            ->assertSee('(2)', false);
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
            'assigned_to_user_id' => $admin->id,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'action_required']))
            ->assertOk()
            ->assertSee('data-bulk-bar', false)
            ->assertSee('data-batch-assign', false)
            ->assertSee('Select one or more rows for batch actions.')
            ->assertDontSee('Clear Selection', false)
            ->assertDontSee('Assign Model', false)
            ->assertSee('Assign Ref. No.')
            ->assertSee('data-bulk-selected-label', false)
            ->assertDontSee('aria-label="Add transaction ID"', false)
            ->assertSee('aria-label="Add service reference"', false)
            ->assertSee('service-case-select', false)
            ->assertSee('transaction-cell-trigger', false)
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
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('service-case-select', false)
            ->assertDontSee('transaction-cell-trigger', false)
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

    public function test_dashboard_sla_tooltip_renders_premium_content_in_template(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 18:46:00'));

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-SLA-HTML',
            'serial_number' => 'SN-SLA-HTML',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $createdAt = now()->subHours(15)->subMinutes(29);
        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-SLA-HTML',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'SLA tooltip html test',
            'description' => 'SLA tooltip html test.',
            'status' => 'open',
            'created_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);
        $incident->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        $response = $this->actingAs($agent)
            ->get(route('dashboard', ['filter' => 'all']))
            ->assertOk();

        $response->assertSee('data-dashboard-tooltip', false);
        $response->assertSee('class="dashboard-tooltip-template"', false);
        $response->assertSee('dashboard-premium-tooltip--compact', false);
        $response->assertSee('dashboard-sla-tooltip-duration--within', false);
        $response->assertSee('15h 29m', false);
        $response->assertDontSee('data-bs-title="&lt;div class=&quot;dashboard-premium-tooltip', false);

        Carbon::setTestNow();
    }

    public function test_dashboard_shows_sla_warning_and_overdue_for_pending_cases(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 12:00:00'));

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-SLA-1',
            'serial_number' => 'SN-SLA-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $warningCreatedAt = now()->subHours(30);
        $warningIncident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-SLA-WARN',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Warning SLA',
            'description' => 'Warning SLA case.',
            'status' => 'open',
            'high_priority' => false,
            'created_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);
        $warningIncident->forceFill([
            'created_at' => $warningCreatedAt,
            'updated_at' => $warningCreatedAt,
        ])->saveQuietly();

        $overdueCreatedAt = now()->subHours(10);
        $overdueIncident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-SLA-OVER',
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Overdue SLA',
            'description' => 'Overdue SLA case.',
            'status' => 'open',
            'high_priority' => true,
            'created_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);
        $overdueIncident->forceFill([
            'created_at' => $overdueCreatedAt,
            'updated_at' => $overdueCreatedAt,
        ])->saveQuietly();

        $this->actingAs($agent)
            ->get(route('dashboard', ['filter' => 'all']))
            ->assertOk()
            ->assertSee('Warning')
            ->assertSee('Overdue')
            ->assertSee('data-bs-title', false);

        Carbon::setTestNow();
    }

    public function test_dashboard_sorts_by_sla_escalation_priority(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 18:00:00'));

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-SLA-SORT',
            'serial_number' => 'SN-SLA-SORT',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $cases = [
            ['ref' => 'SC-SLA-WITHIN', 'hours' => 2, 'high' => false],
            ['ref' => 'SC-SLA-WARN-N', 'hours' => 30, 'high' => false],
            ['ref' => 'SC-SLA-OVER-N', 'hours' => 50, 'high' => false],
            ['ref' => 'SC-SLA-WARN-HP', 'hours' => 5, 'high' => true],
            ['ref' => 'SC-SLA-OVER-HP', 'hours' => 10, 'high' => true],
        ];

        foreach ($cases as $case) {
            $createdAt = now()->subHours($case['hours']);

            $incident = Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => $case['ref'],
                'category' => 'General',
                'source' => IncidentSource::Internal,
                'title' => $case['ref'],
                'description' => 'SLA sort test.',
                'status' => 'open',
                'high_priority' => $case['high'],
                'created_by' => $agent->id,
            ]);

            $incident->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->saveQuietly();
        }

        $sorted = app(\App\Services\DashboardService::class)->recentServiceCases('all', 10);

        $this->assertSame([
            'SC-SLA-OVER-HP',
            'SC-SLA-WARN-HP',
            'SC-SLA-OVER-N',
            'SC-SLA-WARN-N',
            'SC-SLA-WITHIN',
        ], $sorted->pluck('reference_no')->all());

        Carbon::setTestNow();
    }

    public function test_dashboard_sla_alert_cards_filter_table(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 15:00:00'));

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-SLA-FILTER',
            'serial_number' => 'SN-SLA-FILTER',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        foreach ([
            ['ref' => 'SC-FILTER-WARN', 'hours' => 26],
            ['ref' => 'SC-FILTER-OVER', 'hours' => 60],
            ['ref' => 'SC-FILTER-OK', 'hours' => 3],
        ] as $case) {
            $createdAt = now()->subHours($case['hours']);

            $incident = Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => $case['ref'],
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => $case['ref'],
                'description' => $case['ref'],
                'status' => 'open',
                'created_by' => $admin->id,
            ]);

            $incident->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->saveQuietly();
        }

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Overdue')
            ->assertSee('>1</div>', false);

        $this->actingAs($admin)
            ->get(route('dashboard', ['filter' => 'overdue']))
            ->assertOk()
            ->assertSee('SC-FILTER-OVER')
            ->assertDontSee('SC-FILTER-WARN')
            ->assertDontSee('SC-FILTER-OK');

        $this->actingAs($admin)
            ->get(route('dashboard', ['filter' => 'warning']))
            ->assertOk()
            ->assertSee('SC-FILTER-WARN')
            ->assertDontSee('SC-FILTER-OVER')
            ->assertDontSee('SC-FILTER-OK');

        Carbon::setTestNow();
    }

    public function test_completed_service_cases_always_show_within_sla(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 20:00:00'));

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-SLA-DONE',
            'serial_number' => 'SN-SLA-DONE',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TX-DONE',
            'completed_at' => now()->subHour(),
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-SLA-DONE',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Completed SLA',
            'description' => 'Completed SLA case.',
            'status' => 'open',
            'created_by' => $admin->id,
        ]);
        $incident->forceFill([
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ])->saveQuietly();

        $this->actingAs($admin)
            ->get(route('dashboard', ['filter' => 'completed']))
            ->assertOk()
            ->assertSee('Within SLA')
            ->assertDontSee('>Overdue</span>', false);

        Carbon::setTestNow();
    }

    public function test_pending_admin_filter_paginates_matching_cases(): void
    {
        config([
            'dashboard.service_cases_page_size' => 10,
            'dashboard.service_cases_load_more_size' => 10,
        ]);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $references = [];

        for ($index = 1; $index <= 15; $index++) {
            $order = Order::query()->create([
                'order_id' => "RD-PENDING-{$index}",
                'serial_number' => "SN-PENDING-{$index}",
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
                'created_by' => $admin->id,
            ]);

            $reference = sprintf('SC-PENDING-%02d', $index);
            $references[] = $reference;

            Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => $reference,
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => "Pending case {$index}",
                'description' => "Pending case {$index}.",
                'status' => 'open',
                'created_by' => $admin->id,
            ]);
        }

        $dashboardResponse = $this->actingAs($admin)
            ->get(route('dashboard', ['filter' => 'pending_admin']));

        $dashboardResponse->assertOk()
            ->assertSee('10 of 15 Showing')
            ->assertSee('Load More');

        foreach (array_slice($references, 0, 10) as $reference) {
            $dashboardResponse->assertSee($reference);
        }

        foreach (array_slice($references, 10) as $reference) {
            $dashboardResponse->assertDontSee($reference);
        }

        $liveResponse = $this->actingAs($admin)
            ->getJson(route('dashboard.live', ['filter' => 'pending_admin']));

        $liveResponse->assertOk()
            ->assertJsonCount(10, 'rows')
            ->assertJsonPath('total_count', 15)
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('loaded_count', 10);

        $loadMoreResponse = $this->actingAs($admin)
            ->getJson(route('dashboard.service-cases.load-more', [
                'filter' => 'pending_admin',
                'offset' => 10,
            ]));

        $loadMoreResponse->assertOk()
            ->assertJsonCount(5, 'rows')
            ->assertJsonPath('total_count', 15)
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('loaded_count', 15);

        $allFilterResponse = $this->actingAs($admin)
            ->getJson(route('dashboard.live', ['filter' => 'all']));

        $allFilterResponse->assertOk()
            ->assertJsonCount(10, 'rows');
    }

    public function test_load_more_uses_separate_increment_from_initial_page_size(): void
    {
        config([
            'dashboard.service_cases_page_size' => 35,
            'dashboard.service_cases_load_more_size' => 25,
        ]);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $references = [];

        for ($index = 1; $index <= 90; $index++) {
            $order = Order::query()->create([
                'order_id' => "RD-LOAD-{$index}",
                'serial_number' => "SN-LOAD-{$index}",
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
                'created_by' => $admin->id,
            ]);

            $reference = sprintf('SC-LOAD-%02d', $index);
            $references[] = $reference;

            Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => $reference,
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => "Load more case {$index}",
                'description' => "Load more case {$index}.",
                'status' => 'open',
                'created_by' => $admin->id,
            ]);
        }

        $this->actingAs($admin)
            ->get(route('dashboard', ['filter' => 'pending_admin']))
            ->assertOk()
            ->assertSee('35 of 90 Showing');

        $firstLoadMore = $this->actingAs($admin)
            ->getJson(route('dashboard.service-cases.load-more', [
                'filter' => 'pending_admin',
                'offset' => 35,
            ]));

        $firstLoadMore->assertOk()
            ->assertJsonCount(25, 'rows')
            ->assertJsonPath('loaded_count', 60)
            ->assertJsonPath('has_more', true);

        $secondLoadMore = $this->actingAs($admin)
            ->getJson(route('dashboard.service-cases.load-more', [
                'filter' => 'pending_admin',
                'offset' => 60,
            ]));

        $secondLoadMore->assertOk()
            ->assertJsonCount(25, 'rows')
            ->assertJsonPath('loaded_count', 85)
            ->assertJsonPath('has_more', true);
    }

    public function test_open_cases_kpi_links_to_action_required_queue(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $expectedHref = route('dashboard', ['queue' => 'action_required']).'#dashboard-service-cases-panel';

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($expectedHref, false)
            ->assertSee('>Open<', false)
            ->assertSee('Customer Waiting')
            ->assertSee('>Refunds<', false)
            ->assertDontSee('>Pending Approvals<', false)
            ->assertDontSee('>Warning<', false)
            ->assertDontSee('>Open Cases<', false)
            ->assertSee('id="dashboard-service-cases-panel"', false);
    }

    public function test_dashboard_shows_cashfree_awaiting_product_details_service_case(): void
    {
        $admin = User::factory()->create(['name' => 'Admin User']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'CF-ORDER-1392',
            'cashfree_payment_id' => '1453002795',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-01392',
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Cashfree payment — CF-ORDER-1392',
            'description' => 'Automatically created from Cashfree payment webhook. Awaiting product details.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard', ['filter' => 'pending_admin']))
            ->assertOk()
            ->assertSee('SC01392')
            ->assertSee('CF-ORDER-1392')
            ->assertSee('bi-credit-card', false)
            ->assertSee('Pending Admin');

        $this->actingAs($admin)
            ->getJson(route('dashboard.live', ['filter' => 'pending_admin']))
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('incident_ids.0', Incident::query()->first()->id);
    }

    public function test_load_more_hides_when_all_records_are_loaded(): void
    {
        config([
            'dashboard.service_cases_page_size' => 10,
            'dashboard.service_cases_load_more_size' => 10,
        ]);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        for ($index = 1; $index <= 15; $index++) {
            $order = Order::query()->create([
                'order_id' => "RD-HIDE-{$index}",
                'serial_number' => "SN-HIDE-{$index}",
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
                'created_by' => $admin->id,
            ]);

            Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => sprintf('SC-HIDE-%02d', $index),
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => "Hide load more {$index}",
                'description' => "Hide load more {$index}.",
                'status' => 'open',
                'created_by' => $admin->id,
            ]);
        }

        $this->actingAs($admin)
            ->get(route('dashboard', ['filter' => 'pending_admin']))
            ->assertOk()
            ->assertSee('10 of 15 Showing')
            ->assertSee('Load More');

        $finalLoadMore = $this->actingAs($admin)
            ->getJson(route('dashboard.service-cases.load-more', [
                'filter' => 'pending_admin',
                'offset' => 10,
            ]));

        $finalLoadMore->assertOk()
            ->assertJsonCount(5, 'rows')
            ->assertJsonPath('loaded_count', 15)
            ->assertJsonPath('total_count', 15)
            ->assertJsonPath('has_more', false);
    }

    public function test_quick_filter_search_finds_records_beyond_initial_page(): void
    {
        config([
            'dashboard.service_cases_page_size' => 10,
            'dashboard.service_cases_load_more_size' => 10,
        ]);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        for ($index = 1; $index <= 20; $index++) {
            $order = Order::query()->create([
                'order_id' => "RD-MODEL-{$index}",
                'serial_number' => "SN-MODEL-{$index}",
                'product_name' => $index <= 5 ? 'FM 220' : 'MFS 110',
                'device_model' => $index <= 5 ? 'FM 220' : 'MFS 110',
                'status' => 'active',
                'created_by' => $admin->id,
            ]);

            Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => sprintf('SC-MODEL-%02d', $index),
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => "Model search {$index}",
                'description' => "Model search {$index}.",
                'status' => 'open',
                'created_by' => $admin->id,
            ]);
        }

        $searchResponse = $this->actingAs($admin)
            ->getJson(route('dashboard.service-cases.load-more', [
                'filter' => 'pending_admin',
                'offset' => 0,
                'q' => 'FM 220',
            ]));

        $searchResponse->assertOk()
            ->assertJsonCount(5, 'rows')
            ->assertJsonPath('total_count', 5)
            ->assertJsonPath('loaded_count', 5)
            ->assertJsonPath('has_more', false);

        $this->actingAs($admin)
            ->get(route('dashboard', ['filter' => 'pending_admin']))
            ->assertOk()
            ->assertSee('10 of 20 Showing');
    }

    public function test_quick_filter_search_paginates_filtered_results(): void
    {
        config([
            'dashboard.service_cases_page_size' => 35,
            'dashboard.service_cases_load_more_size' => 25,
        ]);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        for ($index = 1; $index <= 90; $index++) {
            $order = Order::query()->create([
                'order_id' => "RD-FILTER-{$index}",
                'serial_number' => "SN-FILTER-{$index}",
                'product_name' => 'FM 220',
                'device_model' => 'FM 220',
                'status' => 'active',
                'created_by' => $admin->id,
            ]);

            Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => sprintf('SC-FILTER-%02d', $index),
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => "Filtered search {$index}",
                'description' => "Filtered search {$index}.",
                'status' => 'open',
                'created_by' => $admin->id,
            ]);
        }

        $initialSearch = $this->actingAs($admin)
            ->getJson(route('dashboard.service-cases.load-more', [
                'filter' => 'pending_admin',
                'offset' => 0,
                'q' => 'FM 220',
            ]));

        $initialSearch->assertOk()
            ->assertJsonCount(35, 'rows')
            ->assertJsonPath('total_count', 90)
            ->assertJsonPath('loaded_count', 35)
            ->assertJsonPath('has_more', true);

        $secondPage = $this->actingAs($admin)
            ->getJson(route('dashboard.service-cases.load-more', [
                'filter' => 'pending_admin',
                'offset' => 35,
                'q' => 'FM 220',
            ]));

        $secondPage->assertOk()
            ->assertJsonCount(25, 'rows')
            ->assertJsonPath('loaded_count', 60)
            ->assertJsonPath('total_count', 90)
            ->assertJsonPath('has_more', true);

        $finalPage = $this->actingAs($admin)
            ->getJson(route('dashboard.service-cases.load-more', [
                'filter' => 'pending_admin',
                'offset' => 60,
                'q' => 'FM 220',
            ]));

        $finalPage->assertOk()
            ->assertJsonCount(25, 'rows')
            ->assertJsonPath('loaded_count', 85)
            ->assertJsonPath('total_count', 90)
            ->assertJsonPath('has_more', true);

        $lastPage = $this->actingAs($admin)
            ->getJson(route('dashboard.service-cases.load-more', [
                'filter' => 'pending_admin',
                'offset' => 85,
                'q' => 'FM 220',
            ]));

        $lastPage->assertOk()
            ->assertJsonCount(5, 'rows')
            ->assertJsonPath('loaded_count', 90)
            ->assertJsonPath('total_count', 90)
            ->assertJsonPath('has_more', false);
    }

    public function test_action_required_count_matches_displayed_grid(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 12:00:00', 'Asia/Kolkata'));

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createAdminOpenCase($admin, 'RD-ACTION-1', assignedTo: $admin, serialNumber: 'B47C11929', deviceModel: 'Access FM220 L1');
        $this->createAdminOpenCase($admin, 'RD-ACTION-2', assignedTo: $admin, serialNumber: 'B47C11930', deviceModel: 'Access FM220 L1');
        $this->createAdminOpenCase(
            $admin,
            'RD-STALE-1',
            createdAt: now()->subHours(20),
            serialNumber: 'B47C11929',
            deviceModel: 'Access FM220 L1',
        );

        $counts = DashboardSnapshot::load()->queueCounts();

        $this->assertSame(3, $counts['action_required']);
        $this->assertSame(0, $counts['pending_review']);

        $response = $this->actingAs($admin)->get(route('dashboard', ['queue' => 'action_required']));

        $response->assertOk()
            ->assertSee('data-dashboard-case-filter-count="action_required">(3)', false)
            ->assertSee('RD-ACTION-1')
            ->assertSee('RD-ACTION-2')
            ->assertSee('RD-STALE-1')
            ->assertSee('3 of 3 Showing');

        $this->actingAs($admin)
            ->getJson(route('dashboard.live', ['queue' => 'action_required']))
            ->assertOk()
            ->assertJsonCount(3, 'rows')
            ->assertJsonPath('total_count', 3);

        Carbon::setTestNow();
    }

    public function test_stale_validated_case_enters_ready_queue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 12:00:00', 'Asia/Kolkata'));

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $staleCase = $this->createAdminOpenCase(
            $admin,
            'RD-STALE-ONLY',
            createdAt: now()->subHours(20),
            serialNumber: 'B47C11929',
            deviceModel: 'Access FM220 L1',
        );

        $classifier = app(OperationsQueueClassifier::class);
        $freshIncident = $staleCase->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments']);

        $this->assertSame('action_required', $classifier->classify($freshIncident)->value);

        $counts = DashboardSnapshot::load()->queueCounts();

        $this->assertSame(1, $counts['action_required']);
        $this->assertSame(0, $counts['pending_review']);

        Carbon::setTestNow();
    }

    public function test_stale_unvalidated_case_stays_out_of_ready_queue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 12:00:00', 'Asia/Kolkata'));

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $staleCase = $this->createAdminOpenCase(
            $admin,
            'RD-STALE-UNVALIDATED',
            createdAt: now()->subHours(20),
        );

        $classifier = app(OperationsQueueClassifier::class);
        $freshIncident = $staleCase->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments']);

        $this->assertNotSame('action_required', $classifier->classify($freshIncident)->value);

        $counts = DashboardSnapshot::load()->queueCounts();

        $this->assertSame(0, $counts['action_required']);

        Carbon::setTestNow();
    }

    public function test_exception_case_remains_in_exceptions_not_ready_queue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 12:00:00', 'Asia/Kolkata'));

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $exceptionCase = $this->createAdminOpenCase(
            $admin,
            'RD-EXCEPTIONS-QUEUE',
            highPriority: true,
        );

        $classifier = app(OperationsQueueClassifier::class);
        $freshIncident = $exceptionCase->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments']);

        $this->assertSame('attention', $classifier->classify($freshIncident)->value);

        $counts = DashboardSnapshot::load()->queueCounts();

        $this->assertSame(0, $counts['action_required']);
        $this->assertSame(1, $counts['attention']);

        $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'attention']))
            ->assertOk()
            ->assertSee('data-dashboard-case-filter-count="attention">(1)', false)
            ->assertSee('RD-EXCEPTIONS-QUEUE')
            ->assertSee('Exceptions');

        Carbon::setTestNow();
    }

    private function createAdminOpenCase(
        User $admin,
        string $orderId,
        ?User $assignedTo = null,
        ?Carbon $createdAt = null,
        bool $highPriority = false,
        ?string $serialNumber = null,
        ?string $deviceModel = null,
    ): Incident {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => $serialNumber ?? 'SN-'.$orderId,
            'product_name' => $deviceModel ?? 'MFS 110',
            'device_model' => $deviceModel ?? 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        if ($serialNumber !== null && $deviceModel !== null) {
            app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);
        }

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => "Case {$orderId}",
            'description' => "Case {$orderId}.",
            'status' => IncidentStatus::Open,
            'high_priority' => $highPriority,
            'assigned_to_user_id' => $assignedTo?->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        if ($createdAt !== null) {
            $incident->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->saveQuietly();
        }

        return $incident->fresh();
    }
}
