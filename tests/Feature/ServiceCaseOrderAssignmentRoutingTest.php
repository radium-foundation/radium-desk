<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Enums\TeamAvailabilityStatus;
use App\Services\IncidentReferenceService;
use App\Services\Operations\PresenceEngineService;
use App\Services\ServiceCaseAssignmentService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCaseOrderAssignmentRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'service_case_assignment.automation_grace_period_enabled' => false,
            'service_case_assignment.round_robin_enabled' => true,
            'service_case_assignment.hardware_order.assignee_email' => 'sumit@radiumbox.com',
        ]);
    }

    private function createSumitUser(bool $active = true): User
    {
        $user = User::factory()->create([
            'name' => 'Sumit',
            'email' => 'sumit@radiumbox.com',
            'is_active' => $active,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

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

    private function createIncident(string $orderId, ?User $actor = null): Incident
    {
        $actor ??= User::factory()->create();

        $order = Order::query()->create([
            'order_id' => $orderId,
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
            'title' => 'Routing test — '.$orderId,
            'description' => 'Routing test.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'created_by' => $actor->id,
        ]);
    }

    public function test_rde_order_assigns_sumit_before_round_robin(): void
    {
        $sumit = $this->createSumitUser();
        $this->createAgentUser('agent-a@test.com', 'Agent Alpha');

        $incident = $this->createIncident('RDE253851');
        $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $incident->creator);

        $this->assertSame($sumit->id, $result->assigned_to_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.assigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);

        $log = AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.assigned')
            ->first();

        $this->assertSame('order_routing', $log->new_values['assignment_method'] ?? null);
        $this->assertSame('hardware_order', $log->new_values['assignment_rule'] ?? null);
    }

    public function test_non_rde_order_continues_round_robin_assignment(): void
    {
        $this->createSumitUser();
        $agentA = $this->createAgentUser('agent-a@test.com', 'Agent Alpha');
        $this->createAgentUser('agent-b@test.com', 'Agent Beta');

        $incident = $this->createIncident('RD-253851');
        $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $incident->creator);

        $this->assertSame($agentA->id, $result->assigned_to_user_id);
    }

    public function test_inactive_sumit_falls_back_to_round_robin(): void
    {
        $this->createSumitUser(active: false);
        $agentA = $this->createAgentUser('agent-a@test.com', 'Agent Alpha');

        $incident = $this->createIncident('RDE999001');
        $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $incident->creator);

        $this->assertSame($agentA->id, $result->assigned_to_user_id);
    }

    public function test_missing_sumit_falls_back_to_round_robin(): void
    {
        $agentA = $this->createAgentUser('agent-a@test.com', 'Agent Alpha');

        $incident = $this->createIncident('RDE888001');
        $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $incident->creator);

        $this->assertSame($agentA->id, $result->assigned_to_user_id);
    }

    public function test_rde_order_assigns_sumit_even_when_grace_period_enabled(): void
    {
        config(['service_case_assignment.automation_grace_period_enabled' => true]);

        $sumit = $this->createSumitUser();
        $this->createAgentUser('agent-a@test.com', 'Agent Alpha');

        $incident = $this->createIncident('RDE777001');
        $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $incident->creator);

        $this->assertSame($sumit->id, $result->assigned_to_user_id);
        $this->assertNull($result->automation_pending_until);
    }

    public function test_rde_prefix_match_is_case_insensitive(): void
    {
        $sumit = $this->createSumitUser();
        $this->createAgentUser('agent-a@test.com', 'Agent Alpha');

        $incident = $this->createIncident('rde123456');
        $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $incident->creator);

        $this->assertSame($sumit->id, $result->assigned_to_user_id);
    }
}
