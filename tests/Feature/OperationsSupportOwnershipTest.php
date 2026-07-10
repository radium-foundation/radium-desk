<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Enums\TeamAvailabilityStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Operations\PresenceEngineService;
use App\Services\ServiceCaseAssignmentEligibilityService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\SettingService;
use App\Services\SupportAppointmentService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OperationsSupportOwnershipTest extends TestCase
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
            'service_case_assignment.hardware_order.assignee_email' => 'sumit@radiumbox.com',
            'smart_assignment.enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_valid_normal_order_assigns_shift_admin(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $this->configureAssignmentSettings($admin->id, $admin->id);
        $this->createAgentUser('agent-a@test.com', 'Agent Alpha');
        $actor = User::factory()->create();

        $incident = $this->createOrderWithIncident($actor, '7881953', 'MFS 110');
        app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $actor);

        $this->assertSame($admin->id, $incident->fresh()->assigned_to_user_id);

        Carbon::setTestNow();
    }

    public function test_invalid_serial_case_assigns_support_agent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $agent = $this->createAgentUser('agent-a@test.com', 'Agent Alpha');
        $this->configureAssignmentSettings($admin->id, $admin->id);
        $actor = User::factory()->create();

        $incident = $this->createOrderWithIncident($actor, 'NOT-VALID', 'MFS 110', $admin);

        app(ServiceCaseAssignmentEligibilityService::class)->evaluateAssignmentEligibility(
            $incident->order,
            $actor,
        );

        $this->assertSame($agent->id, $incident->fresh()->assigned_to_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.reassigned',
            'auditable_id' => $incident->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_support_appointment_case_assigns_support_agent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $agent = $this->createAgentUser('agent-a@test.com', 'Agent Alpha');
        $this->configureAssignmentSettings($admin->id, $admin->id);
        $actor = User::factory()->create();

        $incident = $this->createOrderWithIncident($actor, '7881953', 'MFS 110', $admin);

        app(SupportAppointmentService::class)->book($incident, [
            'preferred_date' => '2026-07-07',
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
        ]);

        $incident->refresh();

        $this->assertSame($agent->id, $incident->assigned_to_user_id);
        $this->assertNotSame($admin->id, $incident->assigned_to_user_id);

        Carbon::setTestNow();
    }

    public function test_offline_agent_skipped_for_invalid_serial_reassignment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('avinash@radiumbox.com', 'Avinash Jha');
        $this->configureAssignmentSettings($admin->id, $admin->id);
        $this->createAgentUser(
            'offline@test.com',
            'Offline Agent',
            availability: TeamAvailabilityStatus::Offline,
        );
        $availableAgent = $this->createAgentUser('available@test.com', 'Available Agent');
        $actor = User::factory()->create();

        $incident = $this->createOrderWithIncident($actor, 'NOT-VALID', 'MFS 110', $admin);

        app(ServiceCaseAssignmentEligibilityService::class)->evaluateAssignmentEligibility(
            $incident->order,
            $actor,
        );

        $this->assertSame($availableAgent->id, $incident->fresh()->assigned_to_user_id);

        Carbon::setTestNow();
    }

    public function test_rde_hardware_assigns_configured_hardware_owner(): void
    {
        $sumit = User::factory()->create([
            'name' => 'Sumit',
            'email' => 'sumit@radiumbox.com',
            'is_active' => true,
        ]);
        $sumit->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        $this->createAgentUser('agent-a@test.com', 'Agent Alpha');

        $actor = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RDE253851',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Hardware routing test',
            'description' => 'Hardware routing test.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'created_by' => $actor->id,
        ]);

        config(['service_case_assignment.automation_grace_period_enabled' => false]);

        $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $actor);

        $this->assertSame($sumit->id, $result->assigned_to_user_id);
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

    private function createAgentUser(
        string $email,
        string $name,
        TeamAvailabilityStatus $availability = TeamAvailabilityStatus::Available,
    ): User {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
            'availability_status' => $availability,
            'availability_updated_at' => now(),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        if ($availability !== TeamAvailabilityStatus::Offline) {
            app(PresenceEngineService::class)->startSession($user);
        }

        return $user->fresh();
    }

    private function createOrderWithIncident(
        User $actor,
        ?string $serial,
        ?string $product,
        ?User $assignee = null,
    ): Incident {
        $order = Order::query()->create([
            'order_id' => 'RD-OWN-'.uniqid(),
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
            'title' => 'Ownership test',
            'description' => 'Ownership test.',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $assignee?->id,
            'created_by' => $actor->id,
        ]);
    }
}
