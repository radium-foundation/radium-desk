<?php

namespace Tests\Feature;

use App\Data\Assignment\SupportAssignmentRequest;
use App\Enums\Assignment\AssignmentTrigger;
use App\Enums\Assignment\SupportAssignmentStrategyType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Assignment\SupportAssignmentEngine;
use App\Services\Operations\PresenceEngineService;
use App\Services\ServiceCaseAssignmentService;
use App\Support\Assignment\Strategies\Support\LeastWorkloadSupportAssignmentStrategy;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\TestCase;

class SupportAssignmentEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'service_case_assignment.automation_grace_period_enabled' => false,
            'service_case_assignment.round_robin_enabled' => true,
            'support_assignment.strategy' => 'round_robin',
            'support_assignment.use_engine' => false,
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_round_robin_strategy_assigns_first_eligible_agent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agentA = $this->createAgent('agent-a@test.com');
        $this->createAgent('agent-b@test.com');

        $incident = $this->createIncident();
        $actor = User::factory()->create();

        $result = app(SupportAssignmentEngine::class)->assign(
            new SupportAssignmentRequest(
                incident: $incident,
                actor: $actor,
                trigger: AssignmentTrigger::OnCreate,
            ),
        );

        $this->assertTrue($result->assigned);
        $this->assertSame($agentA->id, $result->incident->assigned_to_user_id);
        $this->assertSame('round_robin', $result->context['strategy']);

        Carbon::setTestNow();
    }

    public function test_engine_matches_legacy_round_robin_cursor(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agentA = $this->createAgent('agent-a@test.com');
        $agentB = $this->createAgent('agent-b@test.com');
        $actor = User::factory()->create();
        $engine = app(SupportAssignmentEngine::class);
        $legacy = app(ServiceCaseAssignmentService::class);

        $firstIncident = $this->createIncident();
        $secondIncident = $this->createIncident();

        $engineFirst = $engine->assign(new SupportAssignmentRequest($firstIncident, $actor, AssignmentTrigger::OnCreate));
        $legacySecond = $legacy->assignViaRoundRobinAfterGracePeriod($secondIncident, $actor);

        $this->assertSame($agentA->id, $engineFirst->incident->assigned_to_user_id);
        $this->assertSame($agentB->id, $legacySecond->assigned_to_user_id);

        Carbon::setTestNow();
    }

    public function test_default_strategy_is_round_robin(): void
    {
        $this->assertSame(
            SupportAssignmentStrategyType::RoundRobin,
            app(SupportAssignmentEngine::class)->activeStrategyType(),
        );
    }

    public function test_non_round_robin_strategies_are_not_enabled(): void
    {
        $this->expectException(LogicException::class);

        app(LeastWorkloadSupportAssignmentStrategy::class)->selectAssignee(
            collect(),
            new SupportAssignmentRequest(
                incident: $this->createIncident(),
                actor: User::factory()->create(),
                trigger: AssignmentTrigger::OnCreate,
            ),
        );
    }

    public function test_support_assignment_use_engine_defaults_to_false(): void
    {
        $this->assertFalse(config('support_assignment.use_engine'));
    }

    public function test_already_assigned_incident_is_unchanged(): void
    {
        $agent = $this->createAgent('owner@test.com');
        $incident = $this->createIncident($agent);
        $actor = User::factory()->create();

        $result = app(SupportAssignmentEngine::class)->assign(
            new SupportAssignmentRequest($incident, $actor, AssignmentTrigger::OnCreate),
        );

        $this->assertFalse($result->assigned);
        $this->assertSame($agent->id, $result->incident->assigned_to_user_id);
    }

    private function createAgent(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        app(PresenceEngineService::class)->startSession($user);

        return $user->fresh();
    }

    private function createIncident(?User $assignee = null): Incident
    {
        $actor = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-SAE-'.uniqid(),
            'serial_number' => 'SN-SAE-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-SAE-'.uniqid(),
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'Support assignment engine test',
            'description' => '',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $assignee?->id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }
}
