<?php

namespace Tests\Feature;

use App\Contracts\Operations\IraReasoningProvider;
use App\Data\Operations\IraOperationalSnapshotData;
use App\Enums\IraInsightFeedbackResponse;
use App\Enums\IraInsightType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\LeaveRequestStatus;
use App\Enums\PerformancePeriod;
use App\Enums\TeamAvailabilityStatus;
use App\Models\IraInsightFeedback;
use App\Models\IraOperationalMemorySnapshot;
use App\Models\Incident;
use App\Models\LeaveRequest;
use App\Models\Order;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Operations\IraInsightFeedbackService;
use App\Services\Operations\IraMemoryService;
use App\Services\Operations\IraOperationsBrainService;
use App\Services\Operations\IraRecommendationEngineService;
use App\Services\Operations\IraRiskDetectionService;
use App\Services\Operations\OpenAIReasoningProvider;
use App\Services\Operations\RuleBasedReasoningProvider;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class IraOperationsBrainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'ira.thresholds.high_open_cases' => 3,
            'ira.thresholds.high_scheduled_appointments' => 2,
            'ira.thresholds.high_waiting_cases' => 2,
            'ira.thresholds.min_available_staff' => 2,
            'ira.thresholds.sla_risk_cases' => 1,
            'ira.thresholds.member_overload_cases' => 3,
            'ira.thresholds.long_waiting_days' => 7,
            'operations.hardware_order_prefix' => 'FM220',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_daily_memory_snapshot_created(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Memory Agent');
        $this->createIncidentFor($agent, 'RD-MEM-1');

        $snapshot = app(IraMemoryService::class)->capture();

        $this->assertSame('2026-07-05', $snapshot->snapshot_date->toDateString());
        $this->assertGreaterThanOrEqual(1, (int) ($snapshot->operations['open_cases'] ?? 0));
        $this->assertArrayHasKey('available', $snapshot->team);
        $this->assertArrayHasKey('completed_cases', $snapshot->performance);
    }

    public function test_morning_briefing_generated(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 09:30:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Briefing Agent');
        $this->createIncidentFor($agent, 'RD-BRIEF-1');

        $briefing = app(IraOperationsBrainService::class)->briefing(useCache: false);

        $this->assertStringContainsString('Good morning', $briefing->greeting);
        $this->assertNotSame('', $briefing->summary);
        $this->assertNotEmpty($briefing->highlights);
        $this->assertSame('2026-07-05', $briefing->snapshot->date);
    }

    public function test_sla_risk_detected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 12:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('SLA Agent');
        $incident = $this->createIncidentFor($agent, 'RD-SLA-RISK');
        $incident->forceFill([
            'created_at' => Carbon::parse('2026-07-01 12:00:00'),
            'status' => IncidentStatus::Open,
        ])->save();

        $snapshot = app(IraMemoryService::class)->collectSnapshotData();
        $risks = app(IraRiskDetectionService::class)->detect($snapshot);

        $this->assertTrue(
            collect($risks)->contains(fn ($risk): bool => $risk->key === 'customer.sla_danger'),
        );
    }

    public function test_workload_imbalance_detected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithSchedule('Overloaded Agent');

        for ($index = 0; $index < 4; $index++) {
            $this->createIncidentFor($agent, 'RD-LOAD-'.$index);
        }

        $snapshot = app(IraMemoryService::class)->collectSnapshotData();
        $risks = app(IraRiskDetectionService::class)->detect($snapshot);

        $this->assertTrue(
            collect($risks)->contains(fn ($risk): bool => str_starts_with($risk->key, 'team.overload.')),
        );
    }

    public function test_leave_impact_detected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $onLeave = $this->createAgentWithSchedule('Leave Agent');
        LeaveRequest::query()->create([
            'user_id' => $onLeave->id,
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-06',
            'reason' => 'Personal leave',
            'status' => LeaveRequestStatus::Approved,
        ]);

        $briefing = app(IraOperationsBrainService::class)->briefing(useCache: false);

        $this->assertTrue(
            collect($briefing->highlights)->contains(fn (string $line): bool => str_contains($line, 'Leave Agent is on leave')),
        );
    }

    public function test_recommendation_generated(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $availableAgent = $this->createAgentWithSchedule('Capacity Agent');
        $availableAgent->update([
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);

        $incident = $this->createIncidentFor($availableAgent, 'RD-CAP-1');
        \App\Models\SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => '2026-07-06',
            'preferred_time_slot' => \App\Enums\SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9876543210',
        ]);
        $incident->update(['assigned_to_user_id' => null]);

        $snapshot = app(IraMemoryService::class)->collectSnapshotData();
        $risks = app(IraRiskDetectionService::class)->detect($snapshot);
        $recommendations = app(IraRecommendationEngineService::class)->recommend($snapshot, $risks);

        $this->assertTrue(
            collect($recommendations)->contains(
                fn ($recommendation): bool => str_contains($recommendation->message, 'Capacity Agent has capacity'),
            ),
        );
    }

    public function test_feedback_recorded(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $admin = User::factory()->create(['name' => 'Ops Admin']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $feedback = app(IraInsightFeedbackService::class)->record(
            insightKey: 'risk:customer.sla_danger',
            insightType: IraInsightType::Risk,
            response: IraInsightFeedbackResponse::Useful,
            insightPayload: ['overdue' => 2],
            user: $admin,
        );

        $this->assertInstanceOf(IraInsightFeedback::class, $feedback);
        $this->assertDatabaseHas('ira_insight_feedback', [
            'insight_key' => 'risk:customer.sla_danger',
            'response' => IraInsightFeedbackResponse::Useful->value,
            'user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.operations.ira.feedback'), [
                'insight_key' => 'recommendation:sla.prioritize_overdue',
                'insight_type' => IraInsightType::Recommendation->value,
                'response' => IraInsightFeedbackResponse::Incorrect->value,
                'insight_payload' => ['overdue' => 1],
            ])
            ->assertOk()
            ->assertJsonPath('feedback.response', IraInsightFeedbackResponse::Incorrect->value);
    }

    public function test_ai_provider_can_be_swapped_later(): void
    {
        $this->assertInstanceOf(RuleBasedReasoningProvider::class, app(IraReasoningProvider::class));

        config(['ira.reasoning_provider' => 'openai']);

        $this->app->forgetInstance(IraReasoningProvider::class);

        $this->assertInstanceOf(OpenAIReasoningProvider::class, app(IraReasoningProvider::class));

        $snapshot = new IraOperationalSnapshotData(
            date: '2026-07-05',
            operations: ['open_cases' => 1],
            team: ['available' => 1],
            performance: ['completed_cases' => 0],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenAI reasoning provider is not enabled yet.');

        app(OpenAIReasoningProvider::class)->generateBriefing($snapshot, null, [], []);
    }

    public function test_memory_compare_with_yesterday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        IraOperationalMemorySnapshot::query()->create([
            'snapshot_date' => '2026-07-05',
            'operations' => ['open_cases' => 1],
            'team' => ['available' => 3],
            'performance' => ['completed_cases' => 5],
        ]);

        $agent = $this->createAgentWithSchedule('Delta Agent');
        $this->createIncidentFor($agent, 'RD-DELTA-1');
        $this->createIncidentFor($agent, 'RD-DELTA-2');

        $deltas = app(IraMemoryService::class)->compareWithYesterday();

        $this->assertArrayHasKey('operations.open_cases', $deltas);
        $this->assertGreaterThan(0, $deltas['operations.open_cases']);
    }

    public function test_admin_dashboard_shows_ira_briefing_panel(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $admin = User::factory()->create(['name' => 'Ops Admin']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->assertSee('Ira')
            ->assertSee('View Full Analysis');
    }

    private function createAgentWithSchedule(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $user->update([
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        return $user->fresh(['workSchedule']);
    }

    private function createIncidentFor(User $agent, string $orderId = 'RD-IRA-1'): Incident
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => null,
            'customer_name' => 'Ira Customer',
            'customer_email' => 'ira@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Ira brain case',
            'description' => 'Ira brain case.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $agent->id,
        ]);
    }
}
