<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\IncidentBonvoiceCallLink;
use App\Models\Order;
use App\Models\User;
use App\Services\Bonvoice\BonvoiceMissedCallRecoveryService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\PresenceEngineService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BonvoiceMissedCallRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bonvoice.verify_signature' => false,
            'bonvoice.webhook_token' => 'test-bonvoice-token',
            'bonvoice.account_id' => 'acct-001',
            'bonvoice.missed_call_recovery_enabled' => true,
            'cashfree.system_user_email' => 'superadmin@radium.local',
            'service_case_assignment.automation_grace_period_enabled' => true,
            'service_case_assignment.round_robin_enabled' => true,
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        User::factory()->create([
            'name' => 'System',
            'email' => 'superadmin@radium.local',
        ]);
    }

    public function test_feature_flag_disabled_does_not_create_recovery_case(): void
    {
        config(['bonvoice.missed_call_recovery_enabled' => false]);

        $this->seedCustomerOrder('9876543210');

        $this->postMissedCall('call-flag-off-001');

        $this->assertDatabaseMissing('incidents', [
            'category' => BonvoiceMissedCallRecoveryService::CATEGORY,
        ]);
    }

    public function test_missed_call_creates_recovery_case(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 22:00:00', 'Asia/Kolkata'));

        $nightAdmin = $this->createAdminUser('night-admin@test.com', 'Night Admin');
        $this->configureAssignmentSettings(dayAdminId: $nightAdmin->id, nightAdminId: $nightAdmin->id);

        $order = $this->seedCustomerOrder('9876543210');

        $this->postMissedCall('call-missed-001');

        $this->assertDatabaseHas('incidents', [
            'order_id' => $order->id,
            'category' => BonvoiceMissedCallRecoveryService::CATEGORY,
            'high_priority' => true,
            'recovery_phone' => '9876543210',
            'missed_call_attempt_count' => 1,
            'assigned_to_user_id' => $nightAdmin->id,
        ]);

        $incident = Incident::query()->where('category', BonvoiceMissedCallRecoveryService::CATEGORY)->first();
        $this->assertNotNull($incident);
        $this->assertNull($incident->automation_pending_until);

        $this->assertDatabaseHas('incident_bonvoice_call_links', [
            'incident_id' => $incident->id,
            'call_id' => 'call-missed-001',
            'link_type' => 'missed',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'missed_call_recovery.created',
            'auditable_id' => $incident->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_duplicate_missed_call_merges_into_existing_case(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 22:00:00', 'Asia/Kolkata'));

        $nightAdmin = $this->createAdminUser('night-admin@test.com', 'Night Admin');
        $this->configureAssignmentSettings(dayAdminId: $nightAdmin->id, nightAdminId: $nightAdmin->id);

        $this->seedCustomerOrder('9876543210');

        $this->postMissedCall('call-missed-merge-001');
        $this->postMissedCall('call-missed-merge-002');

        $this->assertSame(1, Incident::query()
            ->where('category', BonvoiceMissedCallRecoveryService::CATEGORY)
            ->count());

        $incident = Incident::query()->where('category', BonvoiceMissedCallRecoveryService::CATEGORY)->first();
        $this->assertSame(2, $incident->missed_call_attempt_count);
        $this->assertSame(2, IncidentBonvoiceCallLink::query()->where('incident_id', $incident->id)->count());

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'missed_call_recovery.merged',
            'auditable_id' => $incident->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_working_hours_assigns_round_robin_agent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $dayAdmin = $this->createAdminUser('day-admin@test.com', 'Day Admin');
        $nightAdmin = $this->createAdminUser('night-admin@test.com', 'Night Admin');
        $this->configureAssignmentSettings(dayAdminId: $dayAdmin->id, nightAdminId: $nightAdmin->id);

        $agent = $this->createEligibleAgent('agent@test.com', 'Support Agent');

        $this->seedCustomerOrder('9876543210');

        $this->postMissedCall('call-missed-agent-001');

        $incident = Incident::query()->where('category', BonvoiceMissedCallRecoveryService::CATEGORY)->first();
        $this->assertNotNull($incident);
        $this->assertSame($agent->id, $incident->assigned_to_user_id);
        $this->assertNotSame($dayAdmin->id, $incident->assigned_to_user_id);

        Carbon::setTestNow();
    }

    public function test_working_hours_without_agents_falls_back_to_shift_admin(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $dayAdmin = $this->createAdminUser('day-admin@test.com', 'Day Admin');
        $nightAdmin = $this->createAdminUser('night-admin@test.com', 'Night Admin');
        $this->configureAssignmentSettings(dayAdminId: $dayAdmin->id, nightAdminId: $nightAdmin->id);

        $this->seedCustomerOrder('9876543210');

        $this->postMissedCall('call-missed-fallback-001');

        $incident = Incident::query()->where('category', BonvoiceMissedCallRecoveryService::CATEGORY)->first();
        $this->assertNotNull($incident);
        $this->assertSame($dayAdmin->id, $incident->assigned_to_user_id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'missed_call_recovery.assignment_fallback',
            'auditable_id' => $incident->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_after_hours_assigns_shift_admin(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 22:00:00', 'Asia/Kolkata'));

        $dayAdmin = $this->createAdminUser('day-admin@test.com', 'Day Admin');
        $nightAdmin = $this->createAdminUser('night-admin@test.com', 'Night Admin');
        $this->configureAssignmentSettings(dayAdminId: $dayAdmin->id, nightAdminId: $nightAdmin->id);

        $this->seedCustomerOrder('9876543210');

        $this->postMissedCall('call-missed-after-hours-001');

        $incident = Incident::query()->where('category', BonvoiceMissedCallRecoveryService::CATEGORY)->first();
        $this->assertNotNull($incident);
        $this->assertSame($nightAdmin->id, $incident->assigned_to_user_id);
        $this->assertNotSame($dayAdmin->id, $incident->assigned_to_user_id);

        Carbon::setTestNow();
    }

    public function test_answered_callback_auto_resolves_only_recovery_case(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 22:00:00', 'Asia/Kolkata'));

        $nightAdmin = $this->createAdminUser('night-admin@test.com', 'Night Admin');
        $this->configureAssignmentSettings(dayAdminId: $nightAdmin->id, nightAdminId: $nightAdmin->id);

        $order = $this->seedCustomerOrder('9876543210');

        $otherCase = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Unrelated open case',
            'description' => 'Should remain open.',
            'status' => IncidentStatus::Open,
            'created_by' => $nightAdmin->id,
        ]);

        $this->postMissedCall('call-missed-resolve-001');

        $recoveryCase = Incident::query()
            ->where('category', BonvoiceMissedCallRecoveryService::CATEGORY)
            ->firstOrFail();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-missed-resolve-002',
            status: 'Ringing',
            eventId: 'evt-ringing-002',
        ))->assertOk();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-missed-resolve-002',
            status: 'Answered',
            agentStatus: 'On Call',
            eventId: 'evt-answered-002',
        ))->assertOk();

        $recoveryCase->refresh();
        $otherCase->refresh();

        $this->assertSame(IncidentStatus::Resolved, $recoveryCase->status);
        $this->assertSame(IncidentStatus::Open, $otherCase->status);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'missed_call_recovery.auto_resolved',
            'auditable_id' => $recoveryCase->id,
        ]);

        $this->assertDatabaseHas('incident_bonvoice_call_links', [
            'incident_id' => $recoveryCase->id,
            'call_id' => 'call-missed-resolve-002',
            'link_type' => 'answered',
        ]);

        Carbon::setTestNow();
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

    private function seedCustomerOrder(string $phone): Order
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return Order::query()->create([
            'order_id' => 'RD-MCR-'.uniqid(),
            'serial_number' => 'SN-MCR-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Recovery Customer',
            'customer_phone' => $phone,
            'status' => 'active',
            'created_by' => $creator->id,
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
            'availability_status' => \App\Enums\TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        app(PresenceEngineService::class)->startSession($user);

        return $user->fresh();
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
