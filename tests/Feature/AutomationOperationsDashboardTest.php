<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\AutomationOperationsSnapshotBuilder;
use App\Services\AutomationOperationsSnapshotService;
use App\Services\IncidentReferenceService;
use App\Services\OrderIdentityRepairService;
use App\Services\OrderIdentityValidationAnalyzerService;
use App\Services\ServiceCaseAssignmentEligibilityService;
use App\Services\ServiceCaseAutomationMonitorService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AutomationOperationsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    private function createAdminUser(string $email = 'admin-ops@test.com'): User
    {
        $user = User::factory()->create([
            'name' => 'Ops Admin',
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createAgentUser(string $email = 'agent-ops@test.com'): User
    {
        $user = User::factory()->create([
            'name' => 'Ops Agent',
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    public function test_agent_cannot_access_automation_operations_page(): void
    {
        $agent = $this->createAgentUser();

        $this->actingAs($agent)
            ->get(route('admin.automation.index'))
            ->assertForbidden();
    }

    public function test_admin_can_view_automation_operations_dashboard(): void
    {
        $admin = $this->createAdminUser();
        $agent = $this->createAgentUser();
        $actor = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RD-OPS-PLACEHOLDER',
            'customer_name' => 'Jane Customer',
            'serial_number' => 'FPSPL1141XX',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Placeholder serial',
            'description' => 'Waiting for real serial.',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $actor->id,
        ]);

        app(ServiceCaseAutomationMonitorService::class)->recordPaymentReceived($incident, $actor);

        app(AuditLogService::class)->log(
            userId: $actor->id,
            event: OrderIdentityRepairService::AUDIT_EVENT,
            auditable: $order,
            oldValues: ['serial_number' => 'OLD'],
            newValues: ['serial_number' => 'FPSPL1141XX'],
        );

        $response = $this->actingAs($admin)
            ->get(route('admin.automation.index'));

        $response->assertOk()
            ->assertSee('Automation Operations')
            ->assertSee('Automation Health')
            ->assertSee('Action Queues')
            ->assertSee('Recent Automation')
            ->assertSee('Repair Summary')
            ->assertSee('Validation Summary')
            ->assertSee('Waiting for Customer Serial')
            ->assertSee('Jane Customer')
            ->assertSee($incident->display_reference)
            ->assertSee('RD-OPS-PLACEHOLDER')
            ->assertSee('Payment received')
            ->assertSee('Total Repaired')
            ->assertSee('Last repair run')
            ->assertSee('By Product')
            ->assertSee('By Validator Rule')
            ->assertSee('By Category')
            ->assertDontSee('btn btn-primary">Repair')
            ->assertDontSee('Reassign');
    }

    public function test_superadmin_can_view_automation_operations_dashboard(): void
    {
        $superadmin = User::factory()->create(['is_active' => true]);
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->actingAs($superadmin)
            ->get(route('admin.automation.index'))
            ->assertOk()
            ->assertSee('Automation Operations');
    }

    public function test_dashboard_shows_duplicate_serial_conflicts(): void
    {
        $admin = $this->createAdminUser('admin-dup@test.com');
        $actor = User::factory()->create();

        Order::query()->create([
            'order_id' => 'RD-DUP-1',
            'serial_number' => 'DUPLICATE123',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ])->incidents()->create([
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Duplicate one',
            'description' => 'Duplicate one',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);

        Order::query()->create([
            'order_id' => 'RD-DUP-2',
            'serial_number' => 'DUPLICATE123',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ])->incidents()->create([
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Duplicate two',
            'description' => 'Duplicate two',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.automation.index'))
            ->assertOk()
            ->assertSee('Duplicate Serial Conflicts')
            ->assertSee('DUPLICATE123')
            ->assertSee('RD-DUP-1')
            ->assertSee('RD-DUP-2');
    }

    public function test_dashboard_shows_round_robin_and_shift_admin_events(): void
    {
        $admin = $this->createAdminUser('admin-events@test.com');
        $agent = $this->createAgentUser('agent-events@test.com');
        $actor = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RD-EVENTS-1',
            'serial_number' => '7881953',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Events',
            'description' => 'Events',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);

        app(AuditLogService::class)->log(
            userId: $actor->id,
            event: 'service_case.assigned',
            auditable: $incident,
            oldValues: ['assigned_to_user_id' => null],
            newValues: ['assigned_to_user_id' => $agent->id],
        );

        app(AuditLogService::class)->log(
            userId: $actor->id,
            event: 'service_case.reassigned',
            auditable: $incident,
            oldValues: ['assigned_to_user_id' => $agent->id],
            newValues: [
                'assigned_to_user_id' => $admin->id,
                'reason' => ServiceCaseAssignmentEligibilityService::AUTOMATIC_REASSIGNMENT_REASON,
            ],
        );

        $this->actingAs($admin)
            ->get(route('admin.automation.index'))
            ->assertOk()
            ->assertSee('Round Robin assignment')
            ->assertSee('Shift Admin reassignment');
    }

    public function test_sidebar_shows_automation_link_for_admin(): void
    {
        $admin = $this->createAdminUser('admin-nav@test.com');

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Automation')
            ->assertSee(route('admin.automation.index'), false);
    }

    public function test_dashboard_does_not_call_analyzer_on_http_request(): void
    {
        $this->mock(OrderIdentityValidationAnalyzerService::class, function ($mock): void {
            $mock->shouldNotReceive('analyze');
        });

        $this->actingAs($this->createAdminUser('admin-analyzer@test.com'))
            ->get(route('admin.automation.index'))
            ->assertOk();
    }

    public function test_dashboard_serves_cached_snapshot_without_rebuilding(): void
    {
        app(AutomationOperationsSnapshotService::class)->refresh();

        $this->mock(AutomationOperationsSnapshotBuilder::class, function ($mock): void {
            $mock->shouldNotReceive('build');
        });

        $this->actingAs($this->createAdminUser('admin-cache@test.com'))
            ->get(route('admin.automation.index'))
            ->assertOk();

        $this->assertTrue(Cache::has(AutomationOperationsSnapshotService::CACHE_KEY));
    }

    public function test_automation_snapshot_command_refreshes_cache(): void
    {
        Cache::flush();

        $this->artisan('automation:snapshot')
            ->assertSuccessful()
            ->expectsOutput('Automation operations snapshot refreshed.');

        $this->assertTrue(Cache::has(AutomationOperationsSnapshotService::CACHE_KEY));

        $cached = Cache::get(AutomationOperationsSnapshotService::CACHE_KEY);
        $this->assertIsArray($cached);
        $this->assertArrayHasKey('healthCounts', $cached);
        $this->assertArrayHasKey('validationByCategory', $cached);
    }

    public function test_analyzer_still_performs_full_analysis_for_cli(): void
    {
        $actor = User::factory()->create();

        Order::query()->create([
            'order_id' => 'RD-CLI-ANALYZE',
            'serial_number' => 'FPSPL1141XX',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ])->incidents()->create([
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'CLI analyze',
            'description' => 'CLI analyze',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);

        $this->artisan('orders:analyze-validation')
            ->assertSuccessful()
            ->expectsOutputToContain('RD-CLI-ANALYZE');
    }
}
