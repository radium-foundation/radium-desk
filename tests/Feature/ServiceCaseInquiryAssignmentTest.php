<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Bonvoice\BonvoiceMissedCallRecoveryService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\SmartAssignmentService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ServiceCaseInquiryAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'service_case_assignment.automation_grace_period_enabled' => false,
            'service_case_assignment.round_robin_enabled' => true,
            'bonvoice.verify_signature' => false,
            'bonvoice.webhook_token' => 'test-bonvoice-token',
            'bonvoice.account_id' => 'acct-001',
            'bonvoice.missed_call_recovery_enabled' => true,
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        User::factory()->create([
            'name' => 'System',
            'email' => 'superadmin@radium.local',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_inq_round_robin_assigns_only_agent_or_coordinator(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $specialist = $this->createEligibleSupportSpecialist('specialist@test.com', 'Support Specialist');
        $coordinator = $this->createEligibleCoordinator('coordinator@test.com', 'Customer Coordinator');

        $incident = $this->createInquiryIncident();
        $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $incident->creator);

        $this->assertSame($coordinator->id, $result->assigned_to_user_id);
        $this->assertNotSame($specialist->id, $result->assigned_to_user_id);

        Carbon::setTestNow();
    }

    public function test_support_specialist_is_excluded_from_inquiry_active_support_agents(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $this->createEligibleAgent('agent@test.com', 'Support Agent');
        $specialist = $this->createEligibleSupportSpecialist('specialist@test.com', 'Support Specialist');
        $coordinator = $this->createEligibleCoordinator('coordinator@test.com', 'Customer Coordinator');
        $inquiryOrder = $this->createInquiryOrder('SC-POOL');

        $pool = app(ServiceCaseAssignmentService::class)->activeSupportAgents(order: $inquiryOrder);
        $poolIds = collect($pool)->pluck('id')->all();

        $this->assertContains($coordinator->id, $poolIds);
        $this->assertNotContains($specialist->id, $poolIds);

        Carbon::setTestNow();
    }

    public function test_support_specialist_remains_in_normal_rd_assignment_pool(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $specialist = $this->createEligibleSupportSpecialist('specialist@test.com', 'Support Specialist');
        $incident = $this->createRdIncident();

        $result = app(ServiceCaseAssignmentService::class)->assignOnCreate($incident, $incident->creator);

        $this->assertSame($specialist->id, $result->assigned_to_user_id);

        Carbon::setTestNow();
    }

    public function test_inq_after_hours_bonvoice_recovery_does_not_assign_admin(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 22:00:00', 'Asia/Kolkata'));

        $nightAdmin = $this->createAdminUser('night-admin@test.com', 'Night Admin');
        $this->configureAssignmentSettings(dayAdminId: $nightAdmin->id, nightAdminId: $nightAdmin->id);
        $agent = $this->createEligibleAgent('agent@test.com', 'Support Agent');

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-inq-after-hours',
            status: 'Ringing',
            eventId: 'call-inq-after-hours-ringing',
            sourceNumber: '9123456789',
            callBackParams: ['menu' => '1'],
        ))->assertOk();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-inq-after-hours',
            status: 'NOANSWER',
            eventId: 'call-inq-after-hours-missed',
            sourceNumber: '9123456789',
            callBackParams: ['menu' => '1'],
        ))->assertOk();

        $incident = Incident::query()->where('category', BonvoiceMissedCallRecoveryService::CATEGORY)->first();

        $this->assertNotNull($incident);
        $this->assertTrue($incident->order?->isInquiryOrder());
        $this->assertSame($agent->id, $incident->assigned_to_user_id);
        $this->assertNotSame($nightAdmin->id, $incident->assigned_to_user_id);

        Carbon::setTestNow();
    }

    public function test_inq_smart_assignment_candidates_exclude_support_specialist(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $this->createEligibleAgent('agent@test.com', 'Support Agent');
        $specialist = $this->createEligibleSupportSpecialist('specialist@test.com', 'Support Specialist');
        $this->createEligibleCoordinator('coordinator@test.com', 'Customer Coordinator');
        $inquiryOrder = $this->createInquiryOrder('SC-SMART');

        $candidates = app(SmartAssignmentService::class)->eligibleCandidates(order: $inquiryOrder);
        $candidateIds = collect($candidates)->pluck('id')->all();

        $this->assertNotContains($specialist->id, $candidateIds);
        $this->assertCount(2, $candidateIds);

        Carbon::setTestNow();
    }

    public function test_inq_with_passing_validation_does_not_assign_shift_admin(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('admin@test.com', 'Shift Admin');
        $this->configureAssignmentSettings(dayAdminId: $admin->id, nightAdminId: $admin->id);
        $coordinator = $this->createEligibleCoordinator('coordinator@test.com', 'Customer Coordinator');

        $order = Order::query()->create([
            'order_id' => Order::inquiryOrderIdFromReference('SC-VALID-INQ'),
            'serial_number' => 'SN-VALID-INQ',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $coordinator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-VALID-INQ',
            'category' => 'General Enquiry',
            'source' => IncidentSource::Call,
            'title' => 'Existing device inquiry',
            'description' => 'Existing device inquiry.',
            'status' => IncidentStatus::Open,
            'created_by' => $coordinator->id,
            'updated_by' => $coordinator->id,
        ]);

        $result = app(ServiceCaseAssignmentService::class)->assignToShiftAdminAfterValidation(
            $incident->fresh(['order', 'assignee', 'supportAppointments']),
            $incident->creator,
        );

        $this->assertSame($coordinator->id, $result->assigned_to_user_id);
        $this->assertNotSame($admin->id, $result->assigned_to_user_id);

        Carbon::setTestNow();
    }

    private function createInquiryIncident(): Incident
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = $this->createInquiryOrder('SC-INQ-ASSIGN');

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-INQ-ASSIGN',
            'category' => 'General Enquiry',
            'source' => IncidentSource::Call,
            'title' => 'New contact enquiry',
            'description' => 'New contact enquiry.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    private function createInquiryOrder(string $referenceNo): Order
    {
        $creator = User::factory()->create();

        return Order::query()->create([
            'order_id' => Order::inquiryOrderIdFromReference($referenceNo),
            'serial_number' => '',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);
    }

    private function createRdIncident(): Incident
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-RR-'.uniqid(),
            'serial_number' => 'SN-RR-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'RD assignment test',
            'description' => 'RD assignment test.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
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

    private function createEligibleCoordinator(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR);
        app(PresenceEngineService::class)->startSession($user);

        return $user->fresh();
    }

    private function createEligibleSupportSpecialist(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);
        app(PresenceEngineService::class)->startSession($user);

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>|null  $callBackParams
     * @return array<string, mixed>
     */
    private function inboundCallPayload(
        string $callId,
        string $status,
        string $eventId,
        string $sourceNumber = '9876543210',
        ?array $callBackParams = null,
    ): array {
        return [
            'SourceNumber' => $sourceNumber,
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
            'AgentStatus' => null,
            'eventID' => $eventId,
            'callBackParentID' => null,
            'callBackParams' => $callBackParams,
        ];
    }
}
