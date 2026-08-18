<?php

namespace Tests\Unit\Retention;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Retention\RetentionAuditLogInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetentionAuditLogInspectionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-18 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_inspect_performs_no_writes(): void
    {
        $this->seedAuditLog([
            'event' => 'incoming_email.received',
            'created_at' => now()->subDays(120),
        ]);

        $before = AuditLog::query()->count();

        app(RetentionAuditLogInspectionService::class)->inspect();

        $this->assertSame($before, AuditLog::query()->count());
        $this->assertSame($before, (int) DB::table('audit_logs')->count());
    }

    public function test_age_cohort_predicates_use_created_at_cutoffs(): void
    {
        $this->seedAuditLog([
            'event' => 'service_case.status_changed',
            'created_at' => now()->subDays(400),
        ]);
        $this->seedAuditLog([
            'event' => 'service_case.status_changed',
            'created_at' => now()->subDays(100),
        ]);
        $this->seedAuditLog([
            'event' => 'service_case.status_changed',
            'created_at' => now()->subDays(20),
        ]);

        $summary = app(RetentionAuditLogInspectionService::class)->inspect();

        $this->assertSame(1, $summary->rowsOlderThanDays['365_days']);
        $this->assertSame(1, $summary->rowsOlderThanDays['12_months']);
        $this->assertSame(1, $summary->rowsOlderThanDays['180_days']);
        $this->assertSame(2, $summary->rowsOlderThanDays['90_days']);
        $this->assertSame(2, $summary->rowsOlderThanDays['30_days']);
    }

    public function test_must_keep_family_counts_include_finance_and_operational_events(): void
    {
        $this->seedAuditLog(['event' => 'refund.completed', 'created_at' => now()->subDays(500)]);
        $this->seedAuditLog(['event' => 'cashfree.payment_linked_to_existing_order', 'created_at' => now()->subDays(500)]);
        $this->seedAuditLog(['event' => 'service_case.assigned', 'created_at' => now()->subDays(10)]);
        $this->seedAuditLog(['event' => 'notification.dispatched', 'created_at' => now()->subDays(10)]);
        $this->seedAuditLog(['event' => 'incoming_email.received', 'created_at' => now()->subDays(120)]);

        $summary = app(RetentionAuditLogInspectionService::class)->inspect();

        $this->assertSame(1, $summary->mustKeepFamilyCounts['refund']);
        $this->assertSame(1, $summary->mustKeepFamilyCounts['cashfree']);
        $this->assertSame(1, $summary->mustKeepFamilyCounts['service_case_assignment']);
        $this->assertSame(1, $summary->mustKeepFamilyCounts['notification_dispatch']);
        $this->assertSame(4, $summary->mustKeepFamilyRowTotal);
    }

    public function test_event_categorization_assigns_expected_buckets(): void
    {
        $service = app(RetentionAuditLogInspectionService::class);

        $this->assertSame('incoming_email', $service->categorizeEvent('incoming_email.received'));
        $this->assertSame('service_case', $service->categorizeEvent('service_case.status_changed'));
        $this->assertSame('automation_radiumbox', $service->categorizeEvent('service_case.automation.milestone'));
        $this->assertSame('automation_radiumbox', $service->categorizeEvent('radiumbox.enrichment_completed'));
        $this->assertSame('notification_comms', $service->categorizeEvent('communication_action.lifecycle'));
        $this->assertSame('finance_payment_refund', $service->categorizeEvent('refund.approved'));
        $this->assertSame('ai_workbench', $service->categorizeEvent('ai_workbench.suggestion_viewed'));
        $this->assertSame('missed_call_recovery', $service->categorizeEvent('missed_call_recovery.started'));
        $this->assertSame('workforce', $service->categorizeEvent('workforce.leave.submitted'));
        $this->assertSame('generic_created_deleted', $service->categorizeEvent('created'));
        $this->assertSame('other', $service->categorizeEvent('device-model.bulk-assigned'));
    }

    public function test_incoming_email_noise_candidate_cohort_uses_ninety_day_cutoff(): void
    {
        $service = app(RetentionAuditLogInspectionService::class);

        $this->seedAuditLog([
            'event' => 'incoming_email.received',
            'created_at' => now()->subDays(120),
        ]);
        $this->seedAuditLog([
            'event' => 'incoming_email.ignored',
            'created_at' => now()->subDays(120),
        ]);
        $this->seedAuditLog([
            'event' => 'incoming_email.received',
            'created_at' => now()->subDays(30),
        ]);

        $summary = $service->inspect();

        $this->assertSame(2, $summary->candidateCohorts['incoming_email_noise']['count']);
        $this->assertSame(90, $summary->candidateCohorts['incoming_email_noise']['older_than_days']);
        $this->assertSame(0, $summary->candidateCohorts['incoming_email_noise']['overlapping_must_keep_count']);
    }

    public function test_business_non_email_candidate_excludes_incoming_email_events(): void
    {
        $this->seedAuditLog([
            'event' => 'service_case.status_changed',
            'created_at' => now()->subDays(400),
        ]);
        $this->seedAuditLog([
            'event' => 'incoming_email.received',
            'created_at' => now()->subDays(400),
        ]);

        $summary = app(RetentionAuditLogInspectionService::class)->inspect();

        $this->assertSame(1, $summary->candidateCohorts['business_non_email']['count']);
        $this->assertSame(365, $summary->candidateCohorts['business_non_email']['older_than_days']);
        $this->assertSame(1, $summary->candidateCohorts['business_non_email']['overlapping_must_keep_count']);
    }

    public function test_telemetry_candidate_cohorts_report_empty_when_no_matching_rows(): void
    {
        $this->seedAuditLog([
            'event' => 'service_case.viewed',
            'created_at' => now()->subDays(30),
        ]);

        $summary = app(RetentionAuditLogInspectionService::class)->inspect();

        $this->assertSame(0, $summary->candidateCohorts['telemetry_90d']['count']);
        $this->assertSame(0, $summary->candidateCohorts['telemetry_180d']['count']);
    }

    public function test_telemetry_candidate_cohorts_count_aged_view_events(): void
    {
        $this->seedAuditLog([
            'event' => 'service_case.viewed',
            'created_at' => now()->subDays(120),
        ]);
        $this->seedAuditLog([
            'event' => 'order.viewed',
            'created_at' => now()->subDays(200),
        ]);

        $service = app(RetentionAuditLogInspectionService::class);

        $this->assertSame(2, $service->telemetryCandidateQuery(now(), 90)->count());
        $this->assertSame(1, $service->telemetryCandidateQuery(now(), 180)->count());

        $summary = $service->inspect();

        $this->assertSame(2, $summary->candidateCohorts['telemetry_90d']['count']);
        $this->assertSame(1, $summary->candidateCohorts['telemetry_180d']['count']);
    }

    public function test_empty_table_returns_zeroed_summary(): void
    {
        $summary = app(RetentionAuditLogInspectionService::class)->inspect();

        $this->assertSame(0, $summary->tableTotalRows);
        $this->assertSame([], $summary->countByEvent);
        $this->assertSame(0, $summary->estimatedPayloadBytes);
        $this->assertSame(0, $summary->candidateCohorts['incoming_email_noise']['count']);
        $this->assertSame(0, $summary->candidateCohorts['business_non_email']['count']);
    }

    public function test_truncation_issue_is_reported_as_resolved_in_v4_0_39(): void
    {
        $summary = app(RetentionAuditLogInspectionService::class)->inspect();

        $this->assertSame('resolved', $summary->truncationIssue['status']);
        $this->assertSame('4.0.39', $summary->truncationIssue['resolved_in_version']);
        $this->assertSame(
            'service_case.customer_waiting_closed_cleared',
            $summary->truncationIssue['new_event'],
        );
        $this->assertSame(413, $summary->truncationIssue['observed_error_count_before_fix']);
    }

    public function test_logical_safety_notes_are_exposed_from_config(): void
    {
        $summary = app(RetentionAuditLogInspectionService::class)->inspect();

        $this->assertNotEmpty($summary->logicalSafety);
        $this->assertSame('safe_candidate', $summary->logicalSafety[0]['classification']);
        $this->assertContains('PlatformEmailOperationsService', $summary->logicalSafety[0]['readers']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedAuditLog(array $overrides = []): AuditLog
    {
        $user = User::factory()->create();
        $createdAt = $overrides['created_at'] ?? now();
        unset($overrides['created_at']);

        $log = AuditLog::query()->create(array_merge([
            'user_id' => $user->id,
            'event' => 'service_case.status_changed',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'old_values' => ['status' => 'open'],
            'new_values' => ['status' => 'closed'],
        ], $overrides));

        DB::table('audit_logs')->where('id', $log->id)->update([
            'created_at' => Carbon::parse($createdAt)->toDateTimeString(),
        ]);

        return $log->fresh();
    }
}
