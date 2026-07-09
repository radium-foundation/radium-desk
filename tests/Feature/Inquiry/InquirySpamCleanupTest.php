<?php

namespace Tests\Feature\Inquiry;

use App\Enums\BonvoiceCallLinkType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseCloseExceptionReason;
use App\Models\AuditLog;
use App\Models\BonvoiceCallEvent;
use App\Models\Incident;
use App\Models\IncidentBonvoiceCallLink;
use App\Models\Order;
use App\Models\Remark;
use App\Models\User;
use App\Services\Bonvoice\BonvoiceMissedCallRecoveryService;
use App\Services\IncidentReferenceService;
use App\Services\Inquiry\InquirySpamCleanupService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InquirySpamCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        $this->seed(RolePermissionSeeder::class);

        User::factory()->create([
            'email' => 'superadmin@radium.local',
            'first_name' => 'Ira',
            'last_name' => 'Automation',
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_noinput_untouched_enquiry_is_selected(): void
    {
        $incident = $this->createSpamInquiryIncident('SC-SPAM-001', status: 'NOINPUT');

        $candidates = app(InquirySpamCleanupService::class)->spamCandidates();

        $this->assertCount(1, $candidates);
        $this->assertSame($incident->id, $candidates->first()->id);
        $this->assertNull(app(InquirySpamCleanupService::class)->skipReason($incident->fresh()));
    }

    public function test_case_with_agent_remark_is_skipped(): void
    {
        $incident = $this->createSpamInquiryIncident('SC-SPAM-002', status: 'NOINPUT');
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Remark::query()->create([
            'user_id' => $agent->id,
            'remarkable_type' => $incident->getMorphClass(),
            'remarkable_id' => $incident->id,
            'body' => 'Called customer back.',
        ]);

        $summary = app(InquirySpamCleanupService::class)->cleanup();

        $this->assertSame(1, $summary->totalFound);
        $this->assertSame(0, $summary->casesClosed);
        $this->assertSame(1, $summary->skipped);
        $this->assertSame(['has remarks' => 1], $summary->skipReasons);
        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
    }

    public function test_manually_reassigned_case_is_skipped(): void
    {
        $incident = $this->createSpamInquiryIncident('SC-SPAM-003', status: 'NOINPUT');
        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        AuditLog::query()->create([
            'user_id' => $actor->id,
            'event' => 'service_case.reassigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'new_values' => [
                'override_reason' => 'manual_reassign',
            ],
        ]);

        $summary = app(InquirySpamCleanupService::class)->cleanup();

        $this->assertSame(1, $summary->totalFound);
        $this->assertSame(0, $summary->casesClosed);
        $this->assertSame(1, $summary->skipped);
        $this->assertSame(['manual reassignment' => 1], $summary->skipReasons);
        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
    }

    public function test_real_rd_case_is_skipped(): void
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-SPAM-SKIP-1',
            'serial_number' => 'SN-001',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-RD-001',
            'category' => BonvoiceMissedCallRecoveryService::CATEGORY,
            'source' => IncidentSource::Call,
            'title' => 'Matched missed call',
            'description' => 'Matched missed call.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        $event = $this->createBonvoiceEvent('call-rd-skip-001', 'NOINPUT');
        $this->linkBonvoiceEvent($incident, $event);

        $summary = app(InquirySpamCleanupService::class)->cleanup();

        $this->assertSame(0, $summary->totalFound);
        $this->assertSame(0, $summary->casesClosed);
        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
    }

    public function test_real_inquiry_with_ivr_interaction_is_skipped(): void
    {
        $incident = $this->createSpamInquiryIncident(
            referenceNo: 'SC-REAL-INQ-001',
            status: 'NOANSWER',
            callbackParams: ['menu' => '1', 'option' => 'support'],
        );

        $summary = app(InquirySpamCleanupService::class)->cleanup();

        $this->assertSame(0, $summary->totalFound);
        $this->assertSame(0, $summary->casesClosed);
        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $incident = $this->createSpamInquiryIncident('SC-SPAM-004', status: 'NOINPUT');

        $this->artisan('inquiry-spam:cleanup-noinput', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('Total found: 1')
            ->expectsOutputToContain('SC-SPAM-004')
            ->expectsOutputToContain('Would close: 1')
            ->expectsOutputToContain('Cases closed: 0')
            ->expectsOutputToContain('Skipped: 0')
            ->assertSuccessful();

        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => InquirySpamCleanupService::EVENT_ARCHIVED,
            'auditable_id' => $incident->id,
        ]);
        $this->assertDatabaseMissing('remarks', [
            'remarkable_id' => $incident->id,
            'body' => InquirySpamCleanupService::ARCHIVE_REMARK,
        ]);
    }

    public function test_actual_run_closes_and_audits_spam_enquiry(): void
    {
        $incident = $this->createSpamInquiryIncident('SC-SPAM-005', status: 'NOINPUT');

        $summary = app(InquirySpamCleanupService::class)->cleanup();

        $this->assertSame(1, $summary->totalFound);
        $this->assertSame(1, $summary->casesClosed);
        $this->assertSame(0, $summary->skipped);
        $this->assertSame(['SC-SPAM-005'], $summary->references);

        $incident = $incident->fresh();

        $this->assertSame(IncidentStatus::Closed, $incident->status);

        $this->assertDatabaseHas('remarks', [
            'remarkable_type' => $incident->getMorphClass(),
            'remarkable_id' => $incident->id,
            'body' => InquirySpamCleanupService::ARCHIVE_REMARK,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => InquirySpamCleanupService::EVENT_ARCHIVED,
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);

        $auditLog = AuditLog::query()
            ->where('event', InquirySpamCleanupService::EVENT_ARCHIVED)
            ->where('auditable_id', $incident->id)
            ->first();

        $this->assertSame(
            ServiceCaseCloseExceptionReason::DuplicateServiceCase->value,
            $auditLog?->new_values['resolution_reason'] ?? null,
        );
        $this->assertSame('noinput_spam_enquiry', $auditLog?->new_values['archive_reason'] ?? null);

        $this->assertDatabaseHas('orders', [
            'id' => $incident->order_id,
            'order_id' => Order::inquiryOrderIdFromReference('SC-SPAM-005'),
        ]);
        $this->assertDatabaseHas('bonvoice_call_events', [
            'call_id' => 'call-spam-005',
            'status' => 'NOINPUT',
        ]);
    }

    public function test_before_option_limits_candidates_by_created_at(): void
    {
        Carbon::setTestNow('2026-07-08 12:00:00');
        $oldIncident = $this->createSpamInquiryIncident('SC-SPAM-OLD', status: 'NOINPUT', callId: 'call-spam-old');

        Carbon::setTestNow('2026-07-10 12:00:00');
        $newIncident = $this->createSpamInquiryIncident('SC-SPAM-NEW', status: 'NOINPUT', callId: 'call-spam-new');

        $candidates = app(InquirySpamCleanupService::class)->spamCandidates(
            Carbon::parse('2026-07-09')->startOfDay(),
        );

        $this->assertCount(1, $candidates);
        $this->assertSame($oldIncident->id, $candidates->first()->id);
        $this->assertNotContains($newIncident->id, $candidates->pluck('id')->all());
    }

    private function createSpamInquiryIncident(
        string $referenceNo,
        string $status,
        ?array $callbackParams = null,
        string $callId = 'call-spam-default',
    ): Incident {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => Order::inquiryOrderIdFromReference($referenceNo),
            'serial_number' => '',
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $referenceNo,
            'category' => BonvoiceMissedCallRecoveryService::CATEGORY,
            'source' => IncidentSource::Call,
            'title' => 'Missed call recovery',
            'description' => 'Historical spam enquiry.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        if ($callId === 'call-spam-default') {
            $callId = 'call-'.strtolower(str_replace('SC-', '', $referenceNo));
        }

        $event = $this->createBonvoiceEvent($callId, $status, $callbackParams);
        $this->linkBonvoiceEvent($incident, $event);

        return $incident->fresh(['order', 'bonvoiceCallLinks.bonvoiceCallEvent']);
    }

    private function createBonvoiceEvent(
        string $callId,
        string $status,
        ?array $callbackParams = null,
    ): BonvoiceCallEvent {
        return BonvoiceCallEvent::query()->create([
            'call_id' => $callId,
            'leg' => 'A',
            'event_id' => $callId.'-evt',
            'status' => $status,
            'direction' => 'Inbound',
            'customer_phone' => '9123456789',
            'callback_params' => $callbackParams,
            'payload' => [],
        ]);
    }

    private function linkBonvoiceEvent(Incident $incident, BonvoiceCallEvent $event): void
    {
        IncidentBonvoiceCallLink::query()->create([
            'incident_id' => $incident->id,
            'bonvoice_call_event_id' => $event->id,
            'call_id' => $event->call_id,
            'link_type' => BonvoiceCallLinkType::Missed,
            'linked_at' => now(),
        ]);
    }
}
