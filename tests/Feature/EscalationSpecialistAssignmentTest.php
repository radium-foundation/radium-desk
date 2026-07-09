<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Enums\TeamAvailabilityStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\Bonvoice\BonvoiceMissedCallRecoveryService;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\DashboardPersonalizationService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsRoleService;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\SmartAssignmentService;
use App\Services\Operations\SupportAppointmentSmartAssignmentService;
use App\Services\Operations\TeamTelegramQuietRulesService;
use App\Services\Operations\TeamWorkBriefingService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\ServiceCaseAutomationGraceService;
use App\Services\SettingService;
use App\Services\SupportAppointmentService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EscalationSpecialistAssignmentTest extends TestCase
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
            'smart_assignment.enabled' => true,
            'bonvoice.verify_signature' => false,
            'bonvoice.webhook_token' => 'test-bonvoice-token',
            'bonvoice.account_id' => 'acct-001',
            'bonvoice.missed_call_recovery_enabled' => true,
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_escalation_specialist_is_excluded_from_active_support_agents(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $this->createEligibleAgent('agent@test.com', 'Normal Agent');
        $specialist = $this->createEligibleEscalationSpecialist('escalation@test.com', 'Escalation Specialist');

        $pool = app(ServiceCaseAssignmentService::class)->activeSupportAgents();

        $this->assertCount(1, $pool);
        $this->assertNotContains($specialist->id, collect($pool)->pluck('id'));
    }

    public function test_escalation_specialist_is_excluded_from_smart_assignment_candidates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $this->createEligibleAgent('agent@test.com', 'Normal Agent');
        $specialist = $this->createEligibleEscalationSpecialist('escalation@test.com', 'Escalation Specialist');

        $candidates = app(SmartAssignmentService::class)->eligibleCandidates();

        $this->assertCount(1, $candidates);
        $this->assertNotContains($specialist->id, collect($candidates)->pluck('id'));
    }

    public function test_cashfree_grace_expiry_round_robin_skips_escalation_specialist(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createEligibleAgent('agent@test.com', 'Normal Agent');
        $this->createEligibleEscalationSpecialist('escalation@test.com', 'Escalation Specialist');
        $admin = $this->createAdminUser('admin@test.com', 'Shift Admin');
        $this->configureAssignmentSettings($admin->id, $admin->id);

        $actor = User::factory()->create();
        $incident = $this->createIncidentWithoutSerial($actor, IncidentSource::Cashfree);

        app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $actor);

        Carbon::setTestNow(now()->addSeconds(61));

        $this->assertSame(1, app(ServiceCaseAutomationGraceService::class)->processExpiredGracePeriods());
        $this->assertSame($agent->id, $incident->fresh()->assigned_to_user_id);
    }

    public function test_bonvoice_working_hours_round_robin_skips_escalation_specialist(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $dayAdmin = $this->createAdminUser('day-admin@test.com', 'Day Admin');
        $this->configureAssignmentSettings($dayAdmin->id, $dayAdmin->id);

        $agent = $this->createEligibleAgent('agent@test.com', 'Normal Agent');
        $this->createEligibleEscalationSpecialist('escalation@test.com', 'Escalation Specialist');

        User::factory()->create([
            'name' => 'System',
            'email' => 'superadmin@radium.local',
        ]);

        $this->seedCustomerOrder('9876543210');
        $this->postMissedCall('call-escalation-pool-001');

        $incident = Incident::query()
            ->where('category', BonvoiceMissedCallRecoveryService::CATEGORY)
            ->first();

        $this->assertNotNull($incident);
        $this->assertSame($agent->id, $incident->assigned_to_user_id);
    }

    public function test_manual_correction_grace_expiry_round_robin_skips_escalation_specialist(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createEligibleAgent('agent@test.com', 'Normal Agent');
        $this->createEligibleEscalationSpecialist('escalation@test.com', 'Escalation Specialist');
        $admin = $this->createAdminUser('admin@test.com', 'Shift Admin');
        $this->configureAssignmentSettings($admin->id, $admin->id);

        $actor = User::factory()->create();
        $incident = $this->createIncidentWithoutSerial($actor, IncidentSource::Call);

        app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $actor);

        Carbon::setTestNow(now()->addSeconds(61));

        $this->assertSame(1, app(ServiceCaseAutomationGraceService::class)->processExpiredGracePeriods());
        $this->assertSame($agent->id, $incident->fresh()->assigned_to_user_id);
    }

    public function test_support_appointment_smart_assignment_skips_escalation_specialist(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createEligibleAgent('agent@test.com', 'Normal Agent');
        $this->createEligibleEscalationSpecialist('escalation@test.com', 'Escalation Specialist');

        $incident = $this->createUnassignedIncident();
        $this->bookAppointment($incident);

        $incident->refresh();
        $this->assertSame($agent->id, $incident->assigned_to_user_id);
    }

    public function test_manual_assignment_allows_escalation_specialist(): void
    {
        $admin = $this->createAdminUser('admin@test.com', 'Shift Admin');
        $specialist = $this->createEscalationSpecialist('escalation@test.com', 'Escalation Specialist');
        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $incident = $this->createUnassignedIncident();

        app(ServiceCaseAssignmentService::class)->reassign($incident->fresh(), $specialist, $actor);

        $this->assertSame($specialist->id, $incident->fresh()->assigned_to_user_id);
    }

    public function test_escalation_specialist_uses_support_queues_and_my_work(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $specialist = $this->createEscalationSpecialist('escalation@test.com', 'Escalation Specialist');
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $roles = app(OperationsRoleService::class);
        $this->assertTrue($roles->usesSupportQueues($specialist));
        $this->assertFalse($roles->usesAdminQueues($specialist));
        $this->assertFalse($roles->isNormalAssignmentPool($specialist));

        $personalization = app(DashboardPersonalizationService::class);
        $this->assertSame(DashboardPersonalizationService::QUEUE_MY_WORK, $personalization->defaultQueueFor($specialist));
        $this->assertContains(DashboardPersonalizationService::QUEUE_MY_WORK, $personalization->availableQueuesFor($specialist));

        $assignedCase = $this->createAssignedIncident($specialist, 'RD-ESC-MY-WORK');
        $myWork = DashboardSnapshot::load()->incidentsForQueue('my_work', $specialist);

        $this->assertTrue($myWork->contains(fn (Incident $case): bool => $case->id === $assignedCase->id));
    }

    public function test_escalation_specialist_is_eligible_for_work_schedule_and_briefing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 08:30:00', 'Asia/Kolkata'));

        $specialist = User::factory()->create([
            'name' => 'Escalation Specialist',
            'first_name' => 'Escalation',
            'telegram_chat_id' => '999888777',
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $specialist->assignRole(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST);

        $roles = app(OperationsRoleService::class);
        $this->assertTrue($roles->isTeamMember($specialist));
        $this->assertTrue($roles->isAttendanceTracked($specialist));

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $specialist->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [0],
        ]);

        $recipients = collect(app(TeamWorkBriefingService::class)->recipients())
            ->pluck('id')
            ->all();

        $this->assertContains($specialist->id, $recipients);
        $this->assertTrue(app(TeamTelegramQuietRulesService::class)->shouldSendDailyBriefing($specialist));
    }

    public function test_normal_agent_remains_in_round_robin_pool(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createEligibleAgent('agent@test.com', 'Normal Agent');
        $this->createEligibleEscalationSpecialist('escalation@test.com', 'Escalation Specialist');

        $incident = $this->createUnassignedIncident();
        config(['service_case_assignment.automation_grace_period_enabled' => false]);

        $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $incident->creator);

        $this->assertSame($agent->id, $result->assigned_to_user_id);
    }

    public function test_escalation_specialist_display_label_is_unique(): void
    {
        $roles = app(OperationsRoleService::class);

        $this->assertSame('Escalation Specialist', $roles->displayLabel(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST));
        $this->assertNotSame(
            $roles->displayLabel(RolePermissionSeeder::ROLE_AGENT),
            $roles->displayLabel(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST),
        );
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

    private function createEscalationSpecialist(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST);

        return $user->fresh();
    }

    private function createEligibleAgent(string $email, string $name): User
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

    private function createEligibleEscalationSpecialist(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST);
        app(PresenceEngineService::class)->startSession($user);

        return $user->fresh();
    }

    private function createIncidentWithoutSerial(User $actor, IncidentSource $source): Incident
    {
        $order = Order::query()->create([
            'order_id' => 'RD-ESC-'.uniqid(),
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => $source,
            'title' => 'Escalation pool test',
            'description' => 'Escalation pool test.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);
    }

    private function createUnassignedIncident(string $orderId = 'RD-ESC-POOL-1'): Incident
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'customer_name' => 'Pool Test Customer',
            'customer_email' => 'pool@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Pool test case',
            'description' => 'Pool test case.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => null,
        ]);
    }

    private function createAssignedIncident(User $assignee, string $orderId): Incident
    {
        $incident = $this->createUnassignedIncident($orderId);
        $incident->update(['assigned_to_user_id' => $assignee->id]);

        return $incident->fresh();
    }

    private function bookAppointment(Incident $incident): SupportAppointment
    {
        return app(SupportAppointmentService::class)->book($incident, [
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
            'additional_notes' => 'Need remote support.',
        ]);
    }

    private function seedCustomerOrder(string $phone): Order
    {
        $creator = User::factory()->create();

        return Order::query()->create([
            'order_id' => 'RD-BV-'.uniqid(),
            'serial_number' => 'SN-BV-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'BonVoice Customer',
            'customer_phone' => $phone,
            'status' => 'active',
            'created_by' => $creator->id,
        ]);
    }

    private function postMissedCall(string $callId): void
    {
        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: $callId,
            status: 'Ringing',
            eventId: $callId.'-ringing',
        ))->assertOk();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: $callId,
            status: 'NOANSWER',
            eventId: $callId.'-missed',
        ))->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundCallPayload(
        string $callId,
        string $status,
        string $eventId,
        ?string $agentStatus = null,
    ): array {
        return [
            'SourceNumber' => '9876543210',
            'DestinationNumber' => '1800123456',
            'DisplayNumber' => '1800123456',
            'StartTime' => Carbon::now('Asia/Kolkata')->toIso8601String(),
            'DataSource' => 'IVR',
            'callType' => 'Support',
            'AccountID' => 'acct-001',
            'callID' => $callId,
            'Direction' => 'Inbound',
            'Leg' => 'A',
            'Status' => $status,
            'AgentStatus' => $agentStatus,
            'eventID' => $eventId,
            'callBackParentID' => null,
            'callBackParams' => null,
        ];
    }
}
