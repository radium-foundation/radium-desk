<?php

namespace Tests\Unit\Retention;

use App\Enums\IncomingEmailMessageStatus;
use App\Enums\OutboxEventStatus;
use App\Enums\OutgoingEmailMessageStatus;
use App\Models\Incident;
use App\Models\IncidentIncomingEmailLink;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\OutgoingEmailMessage;
use App\Models\User;
use App\Services\IncomingEmail\IncomingEmailOutboxWriter;
use App\Services\Retention\RetentionIgnoredEmailPruneService;
use App\Services\Retention\RetentionUnknownCustomerPruneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RetentionUnknownCustomerPruneServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-21 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_old_unknown_customer_ignored_email_is_candidate(): void
    {
        $message = $this->seedCandidate([
            'received_at' => '2026-08-01 10:00:00',
            'processed_at' => '2026-08-01 10:05:00',
        ]);

        $summary = app(RetentionUnknownCustomerPruneService::class)->prune(dryRun: true);

        $this->assertSame(1, $summary->candidateCount);
        $this->assertContains($message->id, $summary->sampleCandidateIds);
    }

    public function test_boundary_exactly_at_thirty_days_is_not_candidate(): void
    {
        $this->seedCandidate([
            'received_at' => '2026-08-22 12:00:00',
            'processed_at' => '2026-08-22 12:05:00',
        ]);

        $this->assertSame(0, app(RetentionUnknownCustomerPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public function test_one_second_before_thirty_day_boundary_is_candidate(): void
    {
        $this->seedCandidate([
            'received_at' => '2026-08-22 11:59:59',
            'processed_at' => '2026-08-22 12:00:00',
        ]);

        $this->assertSame(1, app(RetentionUnknownCustomerPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public function test_younger_than_thirty_days_is_excluded(): void
    {
        $this->seedCandidate([
            'received_at' => '2026-09-01 10:00:00',
            'processed_at' => '2026-09-01 10:05:00',
        ]);

        $this->assertSame(0, app(RetentionUnknownCustomerPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public function test_order_linked_email_is_never_candidate(): void
    {
        $admin = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'ORD-'.uniqid(),
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->seedCandidate([
            'order_id' => $order->id,
            'processed_at' => '2026-08-01 10:05:00',
        ]);

        $this->assertSame(0, app(RetentionUnknownCustomerPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public function test_incident_id_present_is_never_candidate(): void
    {
        $incident = $this->seedIncident();

        $this->seedCandidate([
            'incident_id' => $incident->id,
            'processed_at' => '2026-08-01 10:05:00',
        ]);

        $this->assertSame(0, app(RetentionUnknownCustomerPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public function test_missing_processed_at_is_never_candidate(): void
    {
        $this->seedCandidate([
            'processed_at' => null,
        ]);

        $summary = app(RetentionUnknownCustomerPruneService::class)->prune(dryRun: true);

        $this->assertSame(0, $summary->candidateCount);
        $this->assertSame(1, $summary->candidatesWithoutProcessedAt);
    }

    public function test_pending_inbound_outbox_excludes_candidate(): void
    {
        $message = $this->seedCandidate();

        OutboxEvent::query()->create([
            'idempotency_key' => 'retention.unknown-customer.pending.'.$message->id,
            'event_type' => IncomingEmailOutboxWriter::EVENT_TYPE,
            'aggregate_type' => IncomingEmailOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $message->id,
            'payload' => ['incoming_email_message_id' => $message->id],
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
        ]);

        $this->assertSame(0, app(RetentionUnknownCustomerPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public function test_processing_inbound_outbox_excludes_candidate(): void
    {
        $message = $this->seedCandidate();

        OutboxEvent::query()->create([
            'idempotency_key' => 'retention.unknown-customer.processing.'.$message->id,
            'event_type' => IncomingEmailOutboxWriter::EVENT_TYPE,
            'aggregate_type' => IncomingEmailOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $message->id,
            'payload' => ['incoming_email_message_id' => $message->id],
            'status' => OutboxEventStatus::Processing,
            'attempts' => 1,
            'available_at' => now(),
        ]);

        $this->assertSame(0, app(RetentionUnknownCustomerPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public function test_incident_link_fk_excludes_candidate(): void
    {
        $message = $this->seedCandidate();
        $incident = $this->seedIncident();

        IncidentIncomingEmailLink::query()->create([
            'incident_id' => $incident->id,
            'incoming_email_message_id' => $message->id,
            'linked_at' => now(),
        ]);

        $this->assertSame(0, app(RetentionUnknownCustomerPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public function test_outgoing_reply_reference_excludes_candidate(): void
    {
        $message = $this->seedCandidate();
        $admin = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'ORD-'.uniqid(),
            'status' => 'active',
            'created_by' => $admin->id,
        ]);
        $incident = $this->seedIncident($order);

        OutgoingEmailMessage::query()->create([
            'in_reply_to_incoming_email_message_id' => $message->id,
            'incident_id' => $incident->id,
            'order_id' => $order->id,
            'mailbox' => 'support@radiumbox.com',
            'to_email' => 'customer@example.com',
            'subject' => 'Re: Unknown customer',
            'body_html' => '<p>Reply</p>',
            'body_text' => 'Reply',
            'preview' => 'Reply',
            'thread_id' => 'thr-reply',
            'provider' => 'gmail',
            'provider_message_id' => 'gmail-out-reply',
            'sent_by_user_id' => $admin->id,
            'sent_at' => now(),
            'status' => OutgoingEmailMessageStatus::Sent,
        ]);

        $this->assertSame(0, app(RetentionUnknownCustomerPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public function test_needs_review_is_never_candidate(): void
    {
        $this->seedCandidate([
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'processed_at' => '2026-08-01 10:05:00',
        ]);

        $this->assertSame(0, app(RetentionUnknownCustomerPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    #[DataProvider('excludedIgnoreReasonProvider')]
    public function test_other_ignore_reasons_are_never_candidates(string $ignoreReason): void
    {
        $this->seedCandidate([
            'ignore_reason' => $ignoreReason,
            'provider_message_id' => 'reason-'.$ignoreReason,
            'processed_at' => '2026-08-01 10:05:00',
        ]);

        $this->assertSame(0, app(RetentionUnknownCustomerPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public static function excludedIgnoreReasonProvider(): array
    {
        return [
            'own_outbound' => ['own_outbound'],
            'known_system_email' => ['known_system_email'],
            'promotions' => ['promotions'],
        ];
    }

    #[DataProvider('nonIgnoredStatusProvider')]
    public function test_non_ignored_statuses_are_never_candidates(IncomingEmailMessageStatus $status): void
    {
        $this->seedCandidate([
            'status' => $status,
            'ignore_reason' => $status === IncomingEmailMessageStatus::Ignored ? 'unknown_customer' : null,
            'provider_message_id' => 'status-'.$status->value,
            'processed_at' => $status === IncomingEmailMessageStatus::Received ? null : '2026-08-01 10:05:00',
        ]);

        $this->assertSame(0, app(RetentionUnknownCustomerPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public static function nonIgnoredStatusProvider(): array
    {
        return [
            'received' => [IncomingEmailMessageStatus::Received],
            'processing' => [IncomingEmailMessageStatus::Processing],
            'failed' => [IncomingEmailMessageStatus::Failed],
            'linked' => [IncomingEmailMessageStatus::Linked],
            'historical_customer' => [IncomingEmailMessageStatus::HistoricalCustomer],
        ];
    }

    public function test_dry_run_performs_no_writes(): void
    {
        $this->seedCandidate();

        $before = IncomingEmailMessage::query()->count();

        DB::enableQueryLog();

        $summary = app(RetentionUnknownCustomerPruneService::class)->prune(dryRun: true);

        $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $this->assertTrue($summary->dryRun);
        $this->assertSame(0, $summary->deletedCount);
        $this->assertSame($before, IncomingEmailMessage::query()->count());
        $this->assertStringNotContainsString('delete', strtolower($sql));
        $this->assertStringNotContainsString('update', strtolower($sql));
    }

    public function test_execute_mode_deletes_only_unknown_customer_candidates(): void
    {
        $candidate = $this->seedCandidate();
        $this->seedCandidate([
            'ignore_reason' => 'promotions',
            'provider_message_id' => 'promotions-'.uniqid(),
        ]);

        $summary = app(RetentionUnknownCustomerPruneService::class)->prune(
            dryRun: false,
            limit: 10,
        );

        $this->assertFalse($summary->dryRun);
        $this->assertSame(1, $summary->deletedCount);
        $this->assertNull(IncomingEmailMessage::query()->find($candidate->id));
        $this->assertSame(1, IncomingEmailMessage::query()->count());
    }

    public function test_batch_limit_is_enforced(): void
    {
        for ($index = 0; $index < 5; $index++) {
            $this->seedCandidate([
                'provider_message_id' => 'unknown-'.$index,
                'processed_at' => '2026-08-01 10:05:00',
            ]);
        }

        $summary = app(RetentionUnknownCustomerPruneService::class)->prune(
            dryRun: false,
            batchSize: 2,
            limit: 3,
        );

        $this->assertSame(3, $summary->deletedCount);
        $this->assertSame(2, $summary->batchesProcessed);
        $this->assertSame(2, IncomingEmailMessage::query()->count());
    }

    public function test_execute_revalidates_candidates_before_delete(): void
    {
        $candidate = $this->seedCandidate(['provider_message_id' => 'delete-me']);
        $protected = $this->seedCandidate(['provider_message_id' => 'protect-me']);

        $protected->update(['order_id' => $this->seedIncident()->order_id]);

        $summary = app(RetentionUnknownCustomerPruneService::class)->prune(
            dryRun: false,
            limit: 10,
        );

        $this->assertSame(1, $summary->deletedCount);
        $this->assertNull(IncomingEmailMessage::query()->find($candidate->id));
        $this->assertNotNull(IncomingEmailMessage::query()->find($protected->id));
    }

    public function test_database_failure_aborts_execute(): void
    {
        $this->seedCandidate();

        DB::listen(function ($query): void {
            if (str_contains(strtolower($query->sql), 'delete from')) {
                throw new \PDOException('Simulated database failure');
            }
        });

        try {
            app(RetentionUnknownCustomerPruneService::class)->prune(dryRun: false, limit: 1);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('aborted due to database error', $exception->getMessage());
        }
    }

    public function test_manifest_generation_is_deterministic_and_sha256_is_reproducible(): void
    {
        for ($index = 0; $index < 3; $index++) {
            $this->seedCandidate([
                'provider_message_id' => 'manifest-'.$index,
                'processed_at' => '2026-08-01 10:05:00',
            ]);
        }

        $manifestOne = storage_path('app/testing/unknown-customer-manifest-one.txt');
        $manifestTwo = storage_path('app/testing/unknown-customer-manifest-two.txt');

        foreach ([$manifestOne, $manifestTwo] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        app(RetentionUnknownCustomerPruneService::class)->prune(
            dryRun: true,
            manifestPath: $manifestOne,
        );

        app(RetentionUnknownCustomerPruneService::class)->prune(
            dryRun: true,
            manifestPath: $manifestTwo,
        );

        $this->assertFileExists($manifestOne);
        $this->assertSame(file_get_contents($manifestOne), file_get_contents($manifestTwo));
        $this->assertSame(hash_file('sha256', $manifestOne), hash_file('sha256', $manifestTwo));

        unlink($manifestOne);
        unlink($manifestTwo);
    }

    public function test_phase_4b_noise_command_still_excludes_unknown_customer(): void
    {
        $this->seedCandidate([
            'received_at' => '2026-06-01 10:00:00',
            'processed_at' => '2026-06-01 10:05:00',
        ]);

        $this->seedCandidate([
            'ignore_reason' => 'promotions',
            'provider_message_id' => 'promotions-noise',
            'received_at' => '2026-06-01 10:00:00',
            'processed_at' => '2026-06-01 10:05:00',
        ]);

        $summary = app(RetentionIgnoredEmailPruneService::class)->prune(dryRun: true);

        $this->assertSame(1, $summary->candidateCount);
        $this->assertSame(1, $summary->excludedUnknownCustomerCount);
        $this->assertArrayHasKey('promotions', $summary->candidatesByIgnoreReason);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedCandidate(array $overrides = []): IncomingEmailMessage
    {
        $attributes = array_merge([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'gmail',
            'provider_message_id' => 'msg-'.uniqid(),
            'from_email' => 'unknown@example.com',
            'subject' => 'Unknown customer retention test',
            'preview' => 'Preview text',
            'status' => IncomingEmailMessageStatus::Ignored,
            'ignore_reason' => 'unknown_customer',
            'received_at' => '2026-08-01 10:00:00',
            'processed_at' => '2026-08-01 10:05:00',
            'attachment_count' => 0,
            'raw_payload' => ['fixture' => true],
        ], $overrides);

        if (array_key_exists('id', $attributes)) {
            return IncomingEmailMessage::query()->forceCreate($attributes);
        }

        return IncomingEmailMessage::query()->create($attributes);
    }

    private function seedIncident(?Order $order = null): Incident
    {
        $actor = User::factory()->create();
        $order ??= Order::query()->create([
            'order_id' => 'ORD-'.uniqid(),
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-RET-'.uniqid(),
            'category' => 'General',
            'source' => 'email',
            'title' => 'Retention test incident',
            'description' => 'Retention test incident.',
            'status' => 'open',
            'created_by' => $actor->id,
        ]);
    }
}
