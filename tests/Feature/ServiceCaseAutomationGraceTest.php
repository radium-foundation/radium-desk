<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Enums\TeamAvailabilityStatus;
use App\Services\IncidentReferenceService;
use App\Services\Operations\PresenceEngineService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\ServiceCaseAssignmentService;
use App\Services\ServiceCaseAutomationGraceService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ServiceCaseAutomationGraceTest extends TestCase
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
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        app(PresenceEngineService::class)->startSession($user);

        return $user->fresh();
    }

    private function createIncidentWithoutSerial(User $actor): Incident
    {
        $order = Order::query()->create([
            'order_id' => 'RD-GRACE-'.uniqid(),
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Grace period test',
            'description' => 'Awaiting enrichment.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'created_by' => $actor->id,
        ]);
    }

    public function test_assign_on_create_defers_assignment_and_marks_automation_pending(): void
    {
        $this->createAgentUser('agent-a@test.com', 'Agent Alpha');
        $actor = User::factory()->create();

        $incident = $this->createIncidentWithoutSerial($actor);
        $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $actor);

        $this->assertNull($result->assigned_to_user_id);
        $this->assertNotNull($result->automation_pending_until);
        $this->assertTrue($result->automation_pending_until->isFuture());

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.automation_pending',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_validation_success_before_expiry_assigns_shift_admin(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $this->configureAssignmentSettings($admin->id, $admin->id);
        $this->createAgentUser('agent-a@test.com', 'Agent Alpha');

        $actor = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-VALID-1',
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
            'title' => 'Validated case',
            'description' => 'Serial present.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);

        $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $actor);

        $this->assertSame($admin->id, $result->assigned_to_user_id);
        $this->assertNull($result->automation_pending_until);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.assigned',
            'auditable_id' => $incident->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_missing_serial_grace_expiry_does_not_assign_agent(): void
    {
        $this->createAgentUser('agent-a@test.com', 'Agent Alpha');
        $this->createAgentUser('agent-b@test.com', 'Agent Beta');
        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $this->configureAssignmentSettings($admin->id, $admin->id);

        $actor = User::factory()->create();
        $incident = $this->createIncidentWithoutSerial($actor);

        app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $actor);

        Carbon::setTestNow(now()->addSeconds(61));

        $processed = app(ServiceCaseAutomationGraceService::class)->processExpiredGracePeriods();

        $this->assertSame(1, $processed);
        $incident->refresh();
        $this->assertNull($incident->assigned_to_user_id);
        $this->assertNull($incident->automation_pending_until);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'service_case.assigned',
            'auditable_id' => $incident->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_wrong_serial_grace_expiry_still_assigns_agent(): void
    {
        $agentA = $this->createAgentUser('agent-a@test.com', 'Agent Alpha');
        $this->createAgentUser('agent-b@test.com', 'Agent Beta');
        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $this->configureAssignmentSettings($admin->id, $admin->id);

        $actor = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-WRONG-SERIAL-'.uniqid(),
            'serial_number' => '54SAXXC5514586',
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
            'title' => 'Wrong serial grace test',
            'description' => 'Invalid serial present.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);

        app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $actor);

        Carbon::setTestNow(now()->addSeconds(61));

        $processed = app(ServiceCaseAutomationGraceService::class)->processExpiredGracePeriods();

        $this->assertSame(1, $processed);
        $incident->refresh();
        $this->assertSame($agentA->id, $incident->assigned_to_user_id);
        $this->assertNull($incident->automation_pending_until);

        Carbon::setTestNow();
    }

    public function test_radiumbox_enrichment_success_assigns_shift_admin_before_expiry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $this->configureAssignmentSettings($admin->id, $admin->id);
        $this->createAgentUser('agent-a@test.com', 'Agent Alpha');

        $actor = User::factory()->create();
        $incident = $this->createIncidentWithoutSerial($actor);

        app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $actor);

        $order = $incident->order;
        $order->update([
            'serial_number' => '7881953',
            'device_model' => 'MFS 110',
            'product_name' => 'MFS 110',
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id, [
            'lookup_result' => 'data_received',
        ]);

        app(ServiceCaseAutomationGraceService::class)->processOrderEnrichmentCompleted($order->fresh());

        $incident->refresh();
        $this->assertSame($admin->id, $incident->assigned_to_user_id);
        $this->assertNull($incident->automation_pending_until);

        Carbon::setTestNow();
    }

    public function test_expired_grace_processing_is_idempotent(): void
    {
        $this->createAgentUser('agent-a@test.com', 'Agent Alpha');
        $actor = User::factory()->create();
        $incident = $this->createIncidentWithoutSerial($actor);

        app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $actor);

        Carbon::setTestNow(now()->addSeconds(61));

        $service = app(ServiceCaseAutomationGraceService::class);
        $this->assertSame(1, $service->processExpiredGracePeriods());
        $this->assertSame(0, $service->processExpiredGracePeriods());

        $incident->refresh();
        $this->assertNull($incident->assigned_to_user_id);
        $this->assertSame(0, AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.assigned')
            ->count());

        Carbon::setTestNow();
    }

    public function test_manually_assigned_cases_skip_grace_period(): void
    {
        $agent = $this->createAgentUser('assigned@test.com', 'Assigned Agent');
        $actor = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RD-MANUAL-1',
            'serial_number' => 'SN-MANUAL-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Manual assignment',
            'description' => 'Already assigned.',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $actor->id,
        ]);

        $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident->fresh(), $actor);

        $this->assertSame($agent->id, $result->assigned_to_user_id);
        $this->assertNull($result->automation_pending_until);
        $this->assertSame(0, AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.automation_pending')
            ->count());
    }

    public function test_process_automation_pending_command_runs_successfully(): void
    {
        $this->createAgentUser('agent-a@test.com', 'Agent Alpha');
        $actor = User::factory()->create();
        $incident = $this->createIncidentWithoutSerial($actor);

        app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $actor);

        Carbon::setTestNow(now()->addSeconds(61));

        $this->artisan('service-cases:process-automation-pending')
            ->assertSuccessful()
            ->expectsOutput('Processed 1 automation-pending service case(s).');

        Carbon::setTestNow();
    }

    public function test_grace_period_disabled_restores_immediate_round_robin(): void
    {
        config(['service_case_assignment.automation_grace_period_enabled' => false]);

        $agentA = $this->createAgentUser('agent-a@test.com', 'Agent Alpha');
        $actor = User::factory()->create();
        $incident = $this->createIncidentWithoutSerial($actor);

        $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $actor);

        $this->assertSame($agentA->id, $result->assigned_to_user_id);
        $this->assertNull($result->automation_pending_until);
    }

    public function test_pending_radiumbox_sync_does_not_pass_validation(): void
    {
        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $this->configureAssignmentSettings($admin->id, $admin->id);

        $actor = User::factory()->create();
        $incident = $this->createIncidentWithoutSerial($actor);

        app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $actor);

        $order = $incident->order;
        $order->update(['serial_number' => 'SN-PENDING-1']);
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markPending($order->id);

        $result = app(ServiceCaseAutomationGraceService::class)->tryAssignAfterValidation(
            $incident->fresh(['order']),
            $actor,
        );

        $this->assertNull($result);
        $this->assertNull($incident->fresh()->assigned_to_user_id);
    }
}
