<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\ServiceCaseAssignmentEligibilityService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\ServiceCaseAutomationGraceService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServiceCaseAdminEscalationTest extends TestCase
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
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    private function createOrderWithIncident(
        User $actor,
        ?string $serial,
        ?string $product,
        ?User $assignee = null,
    ): Incident {
        $order = Order::query()->create([
            'order_id' => 'RD-ESC-'.uniqid(),
            'serial_number' => $serial,
            'product_name' => $product,
            'device_model' => $product,
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Escalation test',
            'description' => 'Test case.',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $assignee?->id,
            'created_by' => $actor->id,
        ]);
    }

    public function test_unassigned_case_assigns_shift_admin_when_validation_passes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $this->configureAssignmentSettings($admin->id, $admin->id);
        $actor = User::factory()->create();

        $incident = $this->createOrderWithIncident($actor, '7881953', 'MFS 110');
        app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $actor);

        $this->assertSame($admin->id, $incident->fresh()->assigned_to_user_id);

        Carbon::setTestNow();
    }

    public function test_agent_assigned_case_reassigns_to_shift_admin_when_validation_passes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $agent = $this->createAgentUser('agent-a@test.com', 'Agent Alpha');
        $this->configureAssignmentSettings($admin->id, $admin->id);
        $actor = User::factory()->create();

        $incident = $this->createOrderWithIncident($actor, '7881953', 'MFS 110', $agent);

        app(ServiceCaseAssignmentEligibilityService::class)->evaluateAssignmentEligibility(
            $incident->order,
            $actor,
        );

        $incident->refresh();
        $this->assertSame($admin->id, $incident->assigned_to_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.reassigned',
            'auditable_id' => $incident->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_admin_assigned_case_is_not_auto_changed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $otherAdmin = $this->createAdminUser('shipra@radiumbox.com', 'Shipra Kumari');
        $this->configureAssignmentSettings($admin->id, $otherAdmin->id);
        $actor = User::factory()->create();

        $incident = $this->createOrderWithIncident($actor, '7881953', 'MFS 110', $otherAdmin);

        app(ServiceCaseAssignmentEligibilityService::class)->evaluateAssignmentEligibility(
            $incident->order,
            $actor,
        );

        $this->assertSame($otherAdmin->id, $incident->fresh()->assigned_to_user_id);
        $this->assertSame(0, AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.reassigned')
            ->count());

        Carbon::setTestNow();
    }

    public function test_invalid_serial_leaves_agent_assigned(): void
    {
        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $agent = $this->createAgentUser('agent-a@test.com', 'Agent Alpha');
        $this->configureAssignmentSettings($admin->id, $admin->id);
        $actor = User::factory()->create();

        $incident = $this->createOrderWithIncident($actor, 'NOT-VALID', 'MFS 110', $agent);

        app(ServiceCaseAssignmentEligibilityService::class)->evaluateAssignmentEligibility(
            $incident->order,
            $actor,
        );

        $this->assertSame($agent->id, $incident->fresh()->assigned_to_user_id);
    }

    public function test_unsupported_product_with_successful_radiumbox_assigns_admin(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $this->configureAssignmentSettings($admin->id, $admin->id);
        $actor = User::factory()->create();

        $incident = $this->createOrderWithIncident($actor, 'RBX-12345', 'AST 300');
        app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $actor);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($incident->order_id, [
            'lookup_result' => 'data_received',
        ]);

        app(ServiceCaseAssignmentEligibilityService::class)->evaluateAssignmentEligibility(
            $incident->order->fresh(),
            $actor,
        );

        $this->assertSame($admin->id, $incident->fresh()->assigned_to_user_id);

        Carbon::setTestNow();
    }

    public function test_device_model_correction_triggers_escalation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $agent = $this->createAgentUser('agent-a@test.com', 'Agent Alpha');
        $this->configureAssignmentSettings($admin->id, $admin->id);
        $actor = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RD-MODEL-FIX',
            'serial_number' => '7881953',
            'product_name' => 'Unknown',
            'device_model' => 'Unknown',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Model correction',
            'description' => 'Awaiting model.',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $actor->id,
        ]);

        $order->update([
            'device_model' => 'MFS 110',
            'product_name' => 'MFS 110',
        ]);

        app(ServiceCaseAssignmentEligibilityService::class)->evaluateAssignmentEligibility(
            $order->fresh(),
            $actor,
        );

        $this->assertSame($admin->id, $incident->fresh()->assigned_to_user_id);

        Carbon::setTestNow();
    }

    public function test_product_correction_triggers_escalation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $agent = $this->createAgentUser('agent-a@test.com', 'Agent Alpha');
        $this->configureAssignmentSettings($admin->id, $admin->id);
        $actor = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RD-PRODUCT-FIX',
            'serial_number' => '7881953',
            'product_name' => 'Wrong Product',
            'device_model' => 'Wrong Product',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Product correction',
            'description' => 'Awaiting product.',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $actor->id,
        ]);

        $order->update([
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
        ]);

        app(ServiceCaseAssignmentEligibilityService::class)->evaluateAssignmentEligibility(
            $order->fresh(),
            $actor,
        );

        $this->assertSame($admin->id, $incident->fresh()->assigned_to_user_id);

        Carbon::setTestNow();
    }

    public function test_duplicate_grace_cron_processing_is_safe_after_admin_assignment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $this->configureAssignmentSettings($admin->id, $admin->id);
        $this->createAgentUser('agent-a@test.com', 'Agent Alpha');
        $actor = User::factory()->create();

        $incident = $this->createOrderWithIncident($actor, '7881953', 'MFS 110');
        app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $actor);

        Carbon::setTestNow(now()->addSeconds(61));

        $service = app(ServiceCaseAutomationGraceService::class);
        $this->assertSame(0, $service->processExpiredGracePeriods());
        $this->assertSame(0, $service->processExpiredGracePeriods());
        $this->assertSame($admin->id, $incident->fresh()->assigned_to_user_id);
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.assigned')
            ->count());

        Carbon::setTestNow();
    }

    public function test_concurrent_evaluation_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $agent = $this->createAgentUser('agent-a@test.com', 'Agent Alpha');
        $this->configureAssignmentSettings($admin->id, $admin->id);
        $actor = User::factory()->create();

        $incident = $this->createOrderWithIncident($actor, '7881953', 'MFS 110', $agent);
        $order = $incident->order;
        $eligibility = app(ServiceCaseAssignmentEligibilityService::class);

        DB::transaction(function () use ($eligibility, $order, $actor): void {
            $eligibility->evaluateAssignmentEligibility($order, $actor);
        });

        DB::transaction(function () use ($eligibility, $order, $actor): void {
            $eligibility->evaluateAssignmentEligibility($order->fresh(), $actor);
        });

        $incident->refresh();
        $this->assertSame($admin->id, $incident->assigned_to_user_id);
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.reassigned')
            ->count());

        Carbon::setTestNow();
    }
}
