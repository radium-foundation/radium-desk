<?php

namespace Tests\Unit\Retention;

use App\Enums\IncomingEmailMessageStatus;
use App\Enums\OutboxEventStatus;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\IncomingEmail\IncomingEmailOutboxWriter;
use App\Services\Retention\RetentionEmailScheduleService;
use App\Services\Retention\RetentionIgnoredEmailPruneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RetentionEmailScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $manifestDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-21 12:00:00');

        $this->manifestDirectory = storage_path('app/testing/email-retention-schedule');
        File::ensureDirectoryExists($this->manifestDirectory);
        File::cleanDirectory($this->manifestDirectory);

        Config::set('retention.email_retention.scheduler_enabled', true);
        Config::set('retention.email_retention.manifest_directory', $this->manifestDirectory);
        Config::set('retention.email_retention.lock_file', $this->manifestDirectory.'/retention.lock');
        Config::set('retention.email_retention.recovery_runs_root', $this->manifestDirectory.'/recovery-runs');
        Config::set('retention.unknown_customer.manifest_directory', $this->manifestDirectory);
        Config::set('retention.unknown_customer_days', 30);
        Config::set('retention.ignored_email_days', 90);
        Config::set('retention.ignored_email.ignore_reasons', config('retention.approved_noise_ignore_reasons'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_daily_dry_run_does_not_delete_rows(): void
    {
        $this->seedUnknownCustomerCandidate();
        $this->seedNoiseCandidate(['ignore_reason' => 'own_outbound']);

        $before = IncomingEmailMessage::query()->count();
        $result = app(RetentionEmailScheduleService::class)->runDailyDryRun();

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->unknownCustomerCandidates);
        $this->assertSame(1, $result->noiseCandidates);
        $this->assertSame(0, $result->unknownCustomerDeleted);
        $this->assertSame(0, $result->noiseDeleted);
        $this->assertSame($before, IncomingEmailMessage::query()->count());
        $this->assertFileExists((string) $result->auditLogPath);
    }

    public function test_weekly_execute_runs_post_execution_dry_run(): void
    {
        $this->ensureRecoveryBackup();
        $unknown = $this->seedUnknownCustomerCandidate();
        $noise = $this->seedNoiseCandidate(['ignore_reason' => 'known_system_email']);

        $result = app(RetentionEmailScheduleService::class)->runWeeklyExecute();

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->unknownCustomerDeleted);
        $this->assertSame(1, $result->noiseDeleted);
        $this->assertSame(0, $result->postUnknownCustomerCandidates);
        $this->assertSame(0, $result->postNoiseCandidates);
        $this->assertNull(IncomingEmailMessage::query()->find($unknown->id));
        $this->assertNull(IncomingEmailMessage::query()->find($noise->id));
    }

    public function test_lock_prevents_concurrent_execution(): void
    {
        $lockPath = $this->manifestDirectory.'/retention.lock';
        $handle = fopen($lockPath, 'c');
        $this->assertNotFalse($handle);
        flock($handle, LOCK_EX);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('concurrent retention process');

            app(RetentionEmailScheduleService::class)->runDailyDryRun();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function test_unexpected_threshold_aborts_weekly_execute(): void
    {
        Config::set('retention.unknown_customer_days', 45);
        $this->ensureRecoveryBackup();
        $this->seedUnknownCustomerCandidate();

        $result = app(RetentionEmailScheduleService::class)->runWeeklyExecute();

        $this->assertFalse($result->success);
        $this->assertSame(0, $result->unknownCustomerDeleted);
        $this->assertNotEmpty($result->anomalies);
    }

    public function test_unexpected_allowlist_aborts_weekly_execute(): void
    {
        Config::set('retention.ignored_email.ignore_reasons', ['promotions']);
        $this->ensureRecoveryBackup();
        $this->seedUnknownCustomerCandidate();

        $result = app(RetentionEmailScheduleService::class)->runWeeklyExecute();

        $this->assertFalse($result->success);
        $this->assertSame(0, $result->noiseDeleted);
    }

    public function test_order_linked_candidate_aborts_weekly_execute(): void
    {
        $this->ensureRecoveryBackup();
        $this->seedUnknownCustomerCandidate();
        $admin = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'ORD-'.uniqid(),
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->seedUnknownCustomerCandidate(['order_id' => $order->id]);

        $result = app(RetentionEmailScheduleService::class)->runWeeklyExecute();

        $this->assertFalse($result->success);
        $this->assertSame(0, $result->unknownCustomerDeleted);
    }

    public function test_pending_outbox_candidate_aborts_weekly_execute(): void
    {
        $this->ensureRecoveryBackup();
        $this->seedUnknownCustomerCandidate();
        $message = $this->seedUnknownCustomerCandidate();

        OutboxEvent::query()->create([
            'idempotency_key' => 'retention.schedule.pending.'.$message->id,
            'event_type' => IncomingEmailOutboxWriter::EVENT_TYPE,
            'aggregate_type' => IncomingEmailOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $message->id,
            'payload' => ['incoming_email_message_id' => $message->id],
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
        ]);

        $result = app(RetentionEmailScheduleService::class)->runWeeklyExecute();

        $this->assertFalse($result->success);
        $this->assertSame(0, $result->noiseDeleted);
    }

    public function test_growth_anomaly_aborts_weekly_execute(): void
    {
        $this->ensureRecoveryBackup();
        $this->seedUnknownCustomerCandidate();
        File::put($this->manifestDirectory.'/schedule-state.json', json_encode([
            'last_daily_dry_run' => [
                'unknown_customer_candidates' => 1,
                'noise_candidates' => 0,
            ],
        ]));

        $this->seedUnknownCustomerCandidate();
        $this->seedUnknownCustomerCandidate(['provider_message_id' => 'second-unknown']);
        $this->seedUnknownCustomerCandidate(['provider_message_id' => 'third-unknown']);

        $result = app(RetentionEmailScheduleService::class)->runWeeklyExecute();

        $this->assertFalse($result->success);
        $this->assertSame(0, $result->unknownCustomerDeleted);
    }

    public function test_needs_review_is_never_deleted_by_schedule(): void
    {
        $this->ensureRecoveryBackup();
        $this->seedUnknownCustomerCandidate();

        IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'gmail',
            'provider_message_id' => 'needs-review-row',
            'from_email' => 'review@example.com',
            'subject' => 'Needs review',
            'preview' => 'Preview text',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'received_at' => '2026-06-01 10:00:00',
            'processed_at' => '2026-06-01 10:05:00',
            'attachment_count' => 0,
            'raw_payload' => ['fixture' => true],
        ]);

        $result = app(RetentionEmailScheduleService::class)->runWeeklyExecute();

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->needsReviewBacklog);
        $this->assertSame(1, IncomingEmailMessage::query()->where('status', IncomingEmailMessageStatus::NeedsReview)->count());
    }

    public function test_unknown_customer_remains_separate_from_noise(): void
    {
        $this->seedUnknownCustomerCandidate();
        $this->seedNoiseCandidate(['ignore_reason' => 'own_outbound']);

        $unknownSummary = app(RetentionEmailScheduleService::class)->runDailyDryRun();
        $noiseOnly = app(RetentionIgnoredEmailPruneService::class)->prune(dryRun: true);

        $this->assertSame(1, $unknownSummary->unknownCustomerCandidates);
        $this->assertSame(1, $unknownSummary->noiseCandidates);
        $this->assertSame(0, $noiseOnly->excludedUnknownCustomerCount);
        $this->assertArrayHasKey('own_outbound', $noiseOnly->candidatesByIgnoreReason);
    }

    private function ensureRecoveryBackup(): void
    {
        $path = $this->manifestDirectory.'/recovery-runs/20260920T000000Z';
        File::ensureDirectoryExists($path);
        File::put($path.'/database.sql.gz.gpg', 'backup');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedUnknownCustomerCandidate(array $overrides = []): IncomingEmailMessage
    {
        return IncomingEmailMessage::query()->create(array_merge([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'gmail',
            'provider_message_id' => 'unknown-'.uniqid(),
            'from_email' => 'unknown@example.com',
            'subject' => 'Unknown customer schedule test',
            'preview' => 'Preview text',
            'status' => IncomingEmailMessageStatus::Ignored,
            'ignore_reason' => 'unknown_customer',
            'received_at' => '2026-08-01 10:00:00',
            'processed_at' => '2026-08-01 10:05:00',
            'attachment_count' => 0,
            'raw_payload' => ['fixture' => true],
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedNoiseCandidate(array $overrides = []): IncomingEmailMessage
    {
        return IncomingEmailMessage::query()->create(array_merge([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'gmail',
            'provider_message_id' => 'noise-'.uniqid(),
            'from_email' => 'noise@example.com',
            'subject' => 'Noise schedule test',
            'preview' => 'Preview text',
            'status' => IncomingEmailMessageStatus::Ignored,
            'ignore_reason' => 'own_outbound',
            'received_at' => '2026-06-01 10:00:00',
            'processed_at' => '2026-06-01 10:05:00',
            'attachment_count' => 0,
            'raw_payload' => ['fixture' => true],
        ], $overrides));
    }
}
