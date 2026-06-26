<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
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
            ->assertSee('SC00001')
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
            ->assertSee('SLA')
            ->assertSee('Within SLA')
            ->assertSee('Updated')
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
            ->assertSee('data-batch-assign', false)
            ->assertSee('Select one or more rows for batch actions.')
            ->assertSee('Clear Selection')
            ->assertSee('Assign Transaction ID')
            ->assertDontSee('data-batch-transaction-input', false)
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
            ->assertSee('Warning')
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

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-SLA-DONE',
            'serial_number' => 'SN-SLA-DONE',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TX-DONE',
            'completed_at' => now()->subHour(),
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-SLA-DONE',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Completed SLA',
            'description' => 'Completed SLA case.',
            'status' => 'open',
            'created_by' => $agent->id,
        ]);
        $incident->forceFill([
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ])->saveQuietly();

        $this->actingAs($agent)
            ->get(route('dashboard', ['filter' => 'completed']))
            ->assertOk()
            ->assertSee('Within SLA')
            ->assertDontSee('>Overdue</span>', false);

        Carbon::setTestNow();
    }

    public function test_pending_admin_filter_renders_all_matching_cases_without_row_cap(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $references = [];

        for ($index = 1; $index <= 16; $index++) {
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

        $dashboardResponse->assertOk();

        foreach ($references as $reference) {
            $dashboardResponse->assertSee($reference);
        }

        $liveResponse = $this->actingAs($admin)
            ->getJson(route('dashboard.live', ['filter' => 'pending_admin']));

        $liveResponse->assertOk()
            ->assertJsonCount(16, 'rows')
            ->assertJsonCount(16, 'incident_ids');

        $liveHtml = collect($liveResponse->json('rows'))->pluck('html')->implode('');

        foreach ($references as $reference) {
            $this->assertStringContainsString($reference, $liveHtml);
        }

        $allFilterResponse = $this->actingAs($admin)
            ->getJson(route('dashboard.live', ['filter' => 'all']));

        $allFilterResponse->assertOk()
            ->assertJsonCount(10, 'rows');
    }
}
