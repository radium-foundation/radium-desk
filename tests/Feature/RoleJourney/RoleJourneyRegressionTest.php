<?php

namespace Tests\Feature\RoleJourney;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Models\Incident;
use App\Models\LeaveRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Operations\LeaveRequestService;
use App\Services\Operations\OperationsRoleService;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\SmartAssignmentService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\ServiceCaseEscalationService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RoleJourneyRegressionTest extends TestCase
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
            'service_case_assignment.escalation.level_1_email' => 'shubhanshi@radiumbox.com',
        ]);
    }

    public function test_shipra_like_operations_admin_can_approve_agent_leave(): void
    {
        $shipra = $this->createShipraLikeUser();
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $leaveRequest = new LeaveRequest();
        $leaveRequest->setRelation('user', $agent);

        $this->assertTrue(app(LeaveRequestService::class)->canReview($shipra, $leaveRequest));
    }

    public function test_shipra_like_operations_admin_is_excluded_from_round_robin_pool(): void
    {
        $shipra = $this->createShipraLikeUser();

        $roleService = app(OperationsRoleService::class);

        $this->assertFalse($roleService->isNormalAssignmentPool($shipra));

        $poolIds = collect(app(ServiceCaseAssignmentService::class)->activeSupportAgents())
            ->pluck('id')
            ->all();
        $candidateIds = collect(app(SmartAssignmentService::class)->eligibleCandidates())
            ->pluck('id')
            ->all();

        $this->assertNotContains($shipra->id, $poolIds);
        $this->assertNotContains($shipra->id, $candidateIds);
    }

    public function test_shubhanshi_like_escalation_specialist_receives_escalation(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $shubhanshi = $this->createShubhanshiLikeUser();
        $incident = $this->createIncident($agent, assignedTo: $agent);

        app(ServiceCaseEscalationService::class)->escalate(
            incident: $incident,
            actor: $agent,
            reason: 'Needs escalation specialist.',
        );

        $this->assertSame($shubhanshi->id, $incident->fresh()->assigned_to_user_id);
    }

    public function test_shubhanshi_like_escalation_specialist_is_excluded_from_round_robin_pool(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createEligibleAgent('agent@test.com');
        $shubhanshi = $this->createShubhanshiLikeUser();

        $poolIds = collect(app(ServiceCaseAssignmentService::class)->activeSupportAgents())
            ->pluck('id')
            ->all();

        $this->assertContains($agent->id, $poolIds);
        $this->assertNotContains($shubhanshi->id, $poolIds);
        $this->assertFalse(app(OperationsRoleService::class)->isNormalAssignmentPool($shubhanshi));

        Carbon::setTestNow();
    }

    private function createShipraLikeUser(): User
    {
        $user = User::factory()->create([
            'email' => 'shipra@radiumbox.com',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $user->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        return $user;
    }

    private function createShubhanshiLikeUser(): User
    {
        $user = User::factory()->create([
            'email' => 'shubhanshi@radiumbox.com',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST);

        return $user;
    }

    private function createEligibleAgent(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        app(PresenceEngineService::class)->startSession($user);

        return $user->fresh();
    }

    private function createIncident(User $actor, ?User $assignedTo = null): Incident
    {
        $order = Order::query()->create([
            'order_id' => 'RD-RJ-'.uniqid(),
            'serial_number' => 'SN-RJ-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Role journey case',
            'description' => 'Role journey case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $assignedTo?->id,
        ]);
    }
}
