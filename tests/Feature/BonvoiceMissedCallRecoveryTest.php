<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
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
use Illuminate\Support\Facades\Queue;
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
        Queue::fake([RadiumBoxOrderEnrichmentJob::class]);

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

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, function (RadiumBoxOrderEnrichmentJob $job) use ($order): bool {
            return $job->orderId === $order->id;
        });

        Carbon::setTestNow();
    }

    public function test_noinput_does_not_create_recovery_case_but_keeps_call_log(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 22:00:00', 'Asia/Kolkata'));

        $nightAdmin = $this->createAdminUser('night-admin@test.com', 'Night Admin');
        $this->configureAssignmentSettings(dayAdminId: $nightAdmin->id, nightAdminId: $nightAdmin->id);

        $this->seedCustomerOrder('9876543210');

        $this->postMissedCall('call-noinput-001', status: 'NOINPUT');

        $this->assertDatabaseHas('bonvoice_call_events', [
            'call_id' => 'call-noinput-001',
            'status' => 'NOINPUT',
        ]);

        $this->assertDatabaseMissing('incidents', [
            'category' => BonvoiceMissedCallRecoveryService::CATEGORY,
        ]);

        $this->assertSame(0, IncidentBonvoiceCallLink::query()->where('call_id', 'call-noinput-001')->count());

        Carbon::setTestNow();
    }

    public function test_missed_unmatched_with_ivr_input_creates_inquiry_case(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $dayAdmin = $this->createAdminUser('day-admin@test.com', 'Day Admin');
        $nightAdmin = $this->createAdminUser('night-admin@test.com', 'Night Admin');
        $this->configureAssignmentSettings(dayAdminId: $dayAdmin->id, nightAdminId: $nightAdmin->id);

        $agent = $this->createEligibleAgent('agent@test.com', 'Support Agent');

        $this->postMissedCall(
            callId: 'call-unmatched-ivr-001',
            status: 'NOANSWER',
            sourceNumber: '9123456789',
            callBackParams: ['menu' => '1', 'option' => 'support'],
        );

        $incident = Incident::query()->where('category', BonvoiceMissedCallRecoveryService::CATEGORY)->first();
        $this->assertNotNull($incident);
        $this->assertFalse($incident->high_priority);
        $this->assertSame('9123456789', $incident->recovery_phone);
        $this->assertSame($agent->id, $incident->assigned_to_user_id);
        $this->assertTrue($incident->order?->isInquiryOrder());

        Carbon::setTestNow();
    }

    public function test_unmatched_inquiry_merge_does_not_force_high_priority(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $dayAdmin = $this->createAdminUser('day-admin@test.com', 'Day Admin');
        $nightAdmin = $this->createAdminUser('night-admin@test.com', 'Night Admin');
        $this->configureAssignmentSettings(dayAdminId: $dayAdmin->id, nightAdminId: $nightAdmin->id);
        $this->createEligibleAgent('agent@test.com', 'Support Agent');

        $this->postMissedCall(
            callId: 'call-inq-merge-001',
            status: 'NOANSWER',
            sourceNumber: '9123456789',
            callBackParams: ['menu' => '1'],
        );

        $this->postMissedCall(
            callId: 'call-inq-merge-002',
            status: 'NOANSWER',
            sourceNumber: '9123456789',
            callBackParams: ['menu' => '2'],
        );

        $incident = Incident::query()->where('category', BonvoiceMissedCallRecoveryService::CATEGORY)->first();
        $this->assertNotNull($incident);
        $this->assertTrue($incident->order?->isInquiryOrder());
        $this->assertFalse($incident->high_priority);
        $this->assertSame(2, $incident->missed_call_attempt_count);

        Carbon::setTestNow();
    }

    public function test_missed_unmatched_without_ivr_input_does_not_create_case(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 22:00:00', 'Asia/Kolkata'));

        $nightAdmin = $this->createAdminUser('night-admin@test.com', 'Night Admin');
        $this->configureAssignmentSettings(dayAdminId: $nightAdmin->id, nightAdminId: $nightAdmin->id);

        $this->postMissedCall(
            callId: 'call-unmatched-no-ivr-001',
            status: 'NOANSWER',
            sourceNumber: '9123456789',
        );

        $this->assertDatabaseHas('bonvoice_call_events', [
            'call_id' => 'call-unmatched-no-ivr-001',
            'status' => 'NOANSWER',
        ]);

        $this->assertDatabaseMissing('incidents', [
            'category' => BonvoiceMissedCallRecoveryService::CATEGORY,
        ]);

        Carbon::setTestNow();
    }

    public function test_unmatched_missed_call_with_production_dtmf_creates_inquiry_case(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 22:00:00', 'Asia/Kolkata'));

        $nightAdmin = $this->createAdminUser('night-admin@test.com', 'Night Admin');
        $this->configureAssignmentSettings(dayAdminId: $nightAdmin->id, nightAdminId: $nightAdmin->id);

        $this->postJson('/api/webhooks/bonvoice', $this->productionInboundCallPayload(
            callId: 'call-unmatched-dtmf-001',
            status: 'NOANSWER',
            sourceNumber: '9123456789',
            dtmf: '2',
        ))->assertOk();

        $incident = Incident::query()->where('category', BonvoiceMissedCallRecoveryService::CATEGORY)->first();
        $this->assertNotNull($incident);
        $this->assertTrue($incident->order?->isInquiryOrder());

        Carbon::setTestNow();
    }

    public function test_matched_missed_call_dispatches_order_enrichment(): void
    {
        Queue::fake([RadiumBoxOrderEnrichmentJob::class]);

        Carbon::setTestNow(Carbon::parse('2026-07-09 22:00:00', 'Asia/Kolkata'));

        $nightAdmin = $this->createAdminUser('night-admin@test.com', 'Night Admin');
        $this->configureAssignmentSettings(dayAdminId: $nightAdmin->id, nightAdminId: $nightAdmin->id);

        $order = $this->seedCustomerOrder('9876543210');

        $this->postMissedCall('call-enrich-001');

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, function (RadiumBoxOrderEnrichmentJob $job) use ($order): bool {
            return $job->orderId === $order->id;
        });

        Carbon::setTestNow();
    }

    public function test_inquiry_recovery_case_does_not_dispatch_order_enrichment(): void
    {
        Queue::fake([RadiumBoxOrderEnrichmentJob::class]);

        Carbon::setTestNow(Carbon::parse('2026-07-09 22:00:00', 'Asia/Kolkata'));

        $nightAdmin = $this->createAdminUser('night-admin@test.com', 'Night Admin');
        $this->configureAssignmentSettings(dayAdminId: $nightAdmin->id, nightAdminId: $nightAdmin->id);

        $this->postMissedCall(
            callId: 'call-inq-no-enrich-001',
            status: 'NOANSWER',
            sourceNumber: '9123456789',
            callBackParams: ['dtmf' => '2'],
        );

        $incident = Incident::query()->where('category', BonvoiceMissedCallRecoveryService::CATEGORY)->first();
        $this->assertNotNull($incident);
        $this->assertTrue($incident->order?->isInquiryOrder());

        Queue::assertNotPushed(RadiumBoxOrderEnrichmentJob::class);

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

        $this->seedCustomerOrder('9876543210');

        $otherOrder = Order::query()->create([
            'order_id' => 'RD-MCR-OTHER-'.uniqid(),
            'serial_number' => 'SN-MCR-OTHER-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Other Customer',
            'customer_phone' => '9000000001',
            'status' => 'active',
            'created_by' => $nightAdmin->id,
        ]);

        $otherCase = Incident::query()->create([
            'order_id' => $otherOrder->id,
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

    public function test_four_missed_calls_same_customer_create_one_incident_with_four_links(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 22:00:00', 'Asia/Kolkata'));

        $nightAdmin = $this->createAdminUser('night-admin@test.com', 'Night Admin');
        $this->configureAssignmentSettings(dayAdminId: $nightAdmin->id, nightAdminId: $nightAdmin->id);

        $this->seedCustomerOrder('9876543210');

        foreach (['call-four-001', 'call-four-002', 'call-four-003', 'call-four-004'] as $callId) {
            $this->postMissedCall($callId);
        }

        $this->assertSame(1, Incident::query()->where('order_id', Order::query()->where('customer_phone', '9876543210')->value('id'))->whereIn('status', IncidentStatus::operationallyActive())->count());

        $incident = Incident::query()
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->whereHas('order', fn ($query) => $query->where('customer_phone', '9876543210'))
            ->first();

        $this->assertNotNull($incident);
        $this->assertSame(4, $incident->missed_call_attempt_count);
        $this->assertSame(4, IncidentBonvoiceCallLink::query()->where('incident_id', $incident->id)->where('link_type', 'missed')->count());

        Carbon::setTestNow();
    }

    public function test_missed_call_attaches_to_existing_general_service_case(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 22:00:00', 'Asia/Kolkata'));

        $nightAdmin = $this->createAdminUser('night-admin@test.com', 'Night Admin');
        $this->configureAssignmentSettings(dayAdminId: $nightAdmin->id, nightAdminId: $nightAdmin->id);

        $order = $this->seedCustomerOrder('9876543210');

        $generalCase = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Existing service case',
            'description' => 'Open before missed call.',
            'status' => IncidentStatus::Open,
            'created_by' => $nightAdmin->id,
        ]);

        $this->postMissedCall('call-general-merge-001');

        $this->assertSame(1, Incident::query()->where('order_id', $order->id)->whereIn('status', IncidentStatus::operationallyActive())->count());
        $generalCase->refresh();

        $this->assertSame('General', $generalCase->category);
        $this->assertSame(1, $generalCase->missed_call_attempt_count);
        $this->assertDatabaseHas('incident_bonvoice_call_links', [
            'incident_id' => $generalCase->id,
            'call_id' => 'call-general-merge-001',
            'link_type' => 'missed',
        ]);
        $this->assertDatabaseMissing('incidents', [
            'order_id' => $order->id,
            'category' => BonvoiceMissedCallRecoveryService::CATEGORY,
        ]);

        Carbon::setTestNow();
    }

    public function test_assigned_agent_is_preserved_when_missed_call_merges(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $dayAdmin = $this->createAdminUser('day-admin@test.com', 'Day Admin');
        $nightAdmin = $this->createAdminUser('night-admin@test.com', 'Night Admin');
        $this->configureAssignmentSettings(dayAdminId: $dayAdmin->id, nightAdminId: $nightAdmin->id);

        $assignedAgent = $this->createEligibleAgent('assigned-agent@test.com', 'Assigned Agent');
        $roundRobinAgent = $this->createEligibleAgent('round-robin@test.com', 'Round Robin Agent');

        $order = $this->seedCustomerOrder('9876543210');

        $generalCase = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Assigned service case',
            'description' => 'Already assigned.',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $assignedAgent->id,
            'created_by' => $assignedAgent->id,
            'updated_by' => $assignedAgent->id,
        ]);

        $this->postMissedCall('call-preserve-agent-001');
        $this->postMissedCall('call-preserve-agent-002');

        $generalCase->refresh();

        $this->assertSame($assignedAgent->id, $generalCase->assigned_to_user_id);
        $this->assertNotSame($roundRobinAgent->id, $generalCase->assigned_to_user_id);
        $this->assertSame(2, $generalCase->missed_call_attempt_count);

        Carbon::setTestNow();
    }

    public function test_answered_call_attaches_to_existing_general_case_without_resolving(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 22:00:00', 'Asia/Kolkata'));

        $nightAdmin = $this->createAdminUser('night-admin@test.com', 'Night Admin');
        $this->configureAssignmentSettings(dayAdminId: $nightAdmin->id, nightAdminId: $nightAdmin->id);

        $order = $this->seedCustomerOrder('9876543210');

        $generalCase = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'General callback case',
            'description' => 'Should stay open after answered call.',
            'status' => IncidentStatus::Open,
            'created_by' => $nightAdmin->id,
        ]);

        $this->postMissedCall('call-general-answered-001');

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-general-answered-002',
            status: 'Ringing',
            eventId: 'evt-general-ringing-002',
        ))->assertOk();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-general-answered-002',
            status: 'Answered',
            agentStatus: 'On Call',
            eventId: 'evt-general-answered-002',
        ))->assertOk();

        $generalCase->refresh();

        $this->assertSame(IncidentStatus::Open, $generalCase->status);
        $this->assertDatabaseHas('incident_bonvoice_call_links', [
            'incident_id' => $generalCase->id,
            'call_id' => 'call-general-answered-001',
            'link_type' => 'missed',
        ]);
        $this->assertDatabaseHas('incident_bonvoice_call_links', [
            'incident_id' => $generalCase->id,
            'call_id' => 'call-general-answered-002',
            'link_type' => 'answered',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'missed_call_recovery.answered_attached',
            'auditable_id' => $generalCase->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_closed_recovery_case_allows_new_incident_on_next_missed_call(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 22:00:00', 'Asia/Kolkata'));

        $nightAdmin = $this->createAdminUser('night-admin@test.com', 'Night Admin');
        $this->configureAssignmentSettings(dayAdminId: $nightAdmin->id, nightAdminId: $nightAdmin->id);

        $this->seedCustomerOrder('9876543210');

        $this->postMissedCall('call-closed-new-001');

        $firstCase = Incident::query()
            ->where('category', BonvoiceMissedCallRecoveryService::CATEGORY)
            ->firstOrFail();

        $firstCase->update([
            'status' => IncidentStatus::Closed,
            'updated_by' => $nightAdmin->id,
        ]);

        $this->postMissedCall('call-closed-new-002');

        $this->assertSame(2, Incident::query()
            ->where('category', BonvoiceMissedCallRecoveryService::CATEGORY)
            ->count());

        $secondCase = Incident::query()
            ->where('category', BonvoiceMissedCallRecoveryService::CATEGORY)
            ->where('id', '!=', $firstCase->id)
            ->first();

        $this->assertNotNull($secondCase);
        $this->assertTrue($secondCase->isActive());

        Carbon::setTestNow();
    }

    private function postMissedCall(
        string $callId,
        string $status = 'NOANSWER',
        string $sourceNumber = '9876543210',
        ?array $callBackParams = null,
    ): void {
        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: $callId,
            status: 'Ringing',
            eventId: $callId.'-ringing',
            sourceNumber: $sourceNumber,
            callBackParams: $callBackParams,
        ))->assertOk();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: $callId,
            status: $status,
            eventId: $callId.'-missed',
            sourceNumber: $sourceNumber,
            callBackParams: $callBackParams,
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
     * @param  array<string, mixed>|null  $callBackParams
     * @return array<string, mixed>
     */
    private function inboundCallPayload(
        string $callId,
        string $status,
        string $eventId,
        ?string $agentStatus = null,
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
            'AgentStatus' => $agentStatus,
            'eventID' => $eventId,
            'callBackParentID' => null,
            'callBackParams' => $callBackParams,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productionInboundCallPayload(
        string $callId,
        string $status,
        string $sourceNumber = '9876543210',
        ?string $dtmf = null,
    ): array {
        return [
            'SourceNumber' => $sourceNumber,
            'DestinationNumber' => '1800123456',
            'DisplayNumber' => '1204404276',
            'StartTime' => Carbon::now('Asia/Kolkata')->toDateTimeString(),
            'EndTime' => Carbon::now('Asia/Kolkata')->toDateTimeString(),
            'CallDuration' => '45',
            'Status' => $status,
            'Direction' => 'Inbound',
            'ResourceURL' => null,
            'DTMF' => $dtmf,
            'callBackParentID' => null,
            'Network' => 'gsm',
            'DataSource' => 'Bonvoice',
            'AccountID' => 'acct-001',
            'callType' => '2',
            'callID' => $callId,
            'callerCountryCode' => '91',
            'eventID' => $callId.'-evt',
        ];
    }
}
