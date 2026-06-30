<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseAutomationStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\ServiceCaseActivityTimelineService;
use App\Services\ServiceCaseAssignmentEligibilityService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\ServiceCaseAutomationGraceService;
use App\Services\ServiceCaseAutomationHealthService;
use App\Services\ServiceCaseAutomationMonitorService;
use App\Services\ServiceCaseAutomationStatusService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AutomationMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'service_case_assignment.automation_grace_period_enabled' => true,
            'service_case_assignment.round_robin_enabled' => true,
            'automation.display_name' => 'Ira',
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);
    }

    private function configureAssignmentSettings(int $dayAdminId, int $nightAdminId): void
    {
        app(SettingService::class)->setMany([
            'assignment.timezone' => 'Asia/Kolkata',
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $dayAdminId,
            'assignment.night_shift_admin_user_id' => (string) $nightAdminId,
            'assignment.fallback_admin_1_user_id' => '',
            'assignment.fallback_admin_2_user_id' => '',
            'assignment.automation_grace_period_seconds' => '60',
        ]);
    }

    private function createAdminUser(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createAgentUser(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    public function test_automation_timeline_includes_monitoring_events_without_duplicates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $this->configureAssignmentSettings($admin->id, $admin->id);
        $actor = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RD-TIMELINE-1',
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
            'source' => IncidentSource::Cashfree,
            'title' => 'Timeline automation test',
            'description' => 'Monitoring timeline.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);

        $monitor = app(ServiceCaseAutomationMonitorService::class);
        $monitor->recordPaymentReceived($incident, $actor);
        $monitor->recordPaymentReceived($incident, $actor);
        $monitor->recordWaitingRadiumBox($order, $actor);
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);
        $monitor->recordValidationPassed($order, $actor);
        app(ServiceCaseAssignmentService::class)->assignOnCreate($incident->fresh(), $actor);

        $timeline = app(ServiceCaseActivityTimelineService::class)->forIncident($incident->fresh());
        $titles = $timeline->pluck('title')->all();

        $this->assertContains('Payment received', $titles);
        $this->assertContains('Waiting for RadiumBox', $titles);
        $this->assertContains('RadiumBox verification successful', $titles);
        $this->assertContains('Serial validation successful', $titles);
        $this->assertContains('Automation pending (60 seconds)', $titles);
        $this->assertSame(1, collect($titles)->filter(fn (string $title): bool => $title === 'Payment received')->count());

        Carbon::setTestNow();
    }

    public function test_computed_automation_status_for_active_cases(): void
    {
        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $agent = $this->createAgentUser('agent-a@test.com', 'Agent Alpha');
        $this->configureAssignmentSettings($admin->id, $admin->id);
        $actor = User::factory()->create();

        $pendingOrder = Order::query()->create([
            'order_id' => 'RD-STATUS-PENDING',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);
        $pendingIncident = Incident::query()->create([
            'order_id' => $pendingOrder->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Pending',
            'description' => 'Pending',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);
        app(ServiceCaseAssignmentService::class)->assignOnCreate($pendingIncident->fresh(), $actor);

        $agentIncident = Incident::query()->create([
            'order_id' => Order::query()->create([
                'order_id' => 'RD-STATUS-AGENT',
                'serial_number' => 'NOT-VALID',
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
                'created_by' => $actor->id,
            ])->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Agent',
            'description' => 'Agent',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $actor->id,
        ]);

        $statusService = app(ServiceCaseAutomationStatusService::class);

        $this->assertSame(
            ServiceCaseAutomationStatus::AutomationPending,
            $statusService->statusFor($pendingIncident->fresh(['order', 'assignee'])),
        );
        $this->assertSame(
            ServiceCaseAutomationStatus::ValidationFailed,
            $statusService->statusFor($agentIncident->fresh(['order', 'assignee'])),
        );
    }

    public function test_automation_health_command_outputs_counts(): void
    {
        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $this->configureAssignmentSettings($admin->id, $admin->id);
        $actor = User::factory()->create();

        $incident = Incident::query()->create([
            'order_id' => Order::query()->create([
                'order_id' => 'RD-HEALTH-1',
                'status' => 'active',
                'created_by' => $actor->id,
            ])->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Health',
            'description' => 'Health',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);

        app(ServiceCaseAssignmentService::class)->assignOnCreate($incident->fresh(), $actor);

        $this->artisan('automation:health')
            ->assertSuccessful()
            ->expectsOutputToContain('Automation Pending: 1')
            ->expectsOutputToContain('Unassigned: 1');
    }

    public function test_automation_repair_dry_run_lists_candidates_without_changes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $agent = $this->createAgentUser('agent-a@test.com', 'Agent Alpha');
        $this->configureAssignmentSettings($admin->id, $admin->id);
        $actor = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RD-REPAIR-1',
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
            'title' => 'Repair candidate',
            'description' => 'Repair candidate',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $actor->id,
        ]);

        $this->artisan('automation:repair --dry-run')
            ->assertSuccessful()
            ->expectsOutputToContain('Dry run: 1 order(s) would be re-evaluated.')
            ->expectsOutputToContain('RD-REPAIR-1');

        $this->assertSame($agent->id, $incident->fresh()->assigned_to_user_id);

        Carbon::setTestNow();
    }

    public function test_automation_repair_re_evaluates_eligibility_and_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $agent = $this->createAgentUser('agent-a@test.com', 'Agent Alpha');
        $this->configureAssignmentSettings($admin->id, $admin->id);
        $actor = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RD-REPAIR-2',
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
            'title' => 'Repair',
            'description' => 'Repair',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $actor->id,
        ]);

        $this->artisan('automation:repair')->assertSuccessful();
        $this->assertSame($admin->id, $incident->fresh()->assigned_to_user_id);

        $this->artisan('automation:repair')->assertSuccessful();
        $this->assertSame($admin->id, $incident->fresh()->assigned_to_user_id);

        Carbon::setTestNow();
    }

    public function test_admin_dashboard_shows_automation_health_widget(): void
    {
        $admin = $this->createAdminUser('admin-widget@test.com', 'Widget Admin');

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Automation Health')
            ->assertSee('Repair Needed');
    }
}
