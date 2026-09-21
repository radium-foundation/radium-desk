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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RetentionIgnoredEmailPruneServiceTest extends TestCase
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

    public function test_old_ignored_no_order_processed_email_is_candidate(): void
    {
        $message = $this->seedCandidate([
            'ignore_reason' => 'own_outbound',
            'received_at' => '2026-06-01 10:00:00',
            'processed_at' => '2026-06-01 10:05:00',
        ]);

        $summary = app(RetentionIgnoredEmailPruneService::class)->prune(dryRun: true);

        $this->assertSame(1, $summary->candidateCount);
        $this->assertContains($message->id, $summary->sampleCandidateIds);
    }

    public function test_new_ignored_email_is_not_candidate(): void
    {
        $this->seedCandidate([
            'ignore_reason' => 'own_outbound',
            'received_at' => '2026-09-01 10:00:00',
            'processed_at' => '2026-09-01 10:05:00',
        ]);

        $this->assertSame(0, app(RetentionIgnoredEmailPruneService::class)->prune(dryRun: true)->candidateCount);
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
            'ignore_reason' => 'own_outbound',
            'order_id' => $order->id,
            'processed_at' => '2026-06-01 10:05:00',
        ]);

        $this->assertSame(0, app(RetentionIgnoredEmailPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public function test_historical_customer_is_never_candidate(): void
    {
        $admin = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'ORD-'.uniqid(),
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->seedCandidate([
            'status' => IncomingEmailMessageStatus::HistoricalCustomer,
            'ignore_reason' => null,
            'order_id' => $order->id,
            'processed_at' => '2026-06-01 10:05:00',
        ]);

        $this->assertSame(0, app(RetentionIgnoredEmailPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public function test_needs_review_is_never_candidate(): void
    {
        $this->seedCandidate([
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'ignore_reason' => 'unknown_customer',
            'processed_at' => '2026-06-01 10:05:00',
        ]);

        $this->assertSame(0, app(RetentionIgnoredEmailPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    #[DataProvider('nonIgnoredStatusProvider')]
    public function test_non_ignored_statuses_are_never_candidates(IncomingEmailMessageStatus $status): void
    {
        $this->seedCandidate([
            'status' => $status,
            'ignore_reason' => null,
            'provider_message_id' => 'status-'.$status->value,
            'processed_at' => $status === IncomingEmailMessageStatus::Received ? null : '2026-06-01 10:05:00',
        ]);

        $this->assertSame(0, app(RetentionIgnoredEmailPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public static function nonIgnoredStatusProvider(): array
    {
        return [
            'received' => [IncomingEmailMessageStatus::Received],
            'processing' => [IncomingEmailMessageStatus::Processing],
            'failed' => [IncomingEmailMessageStatus::Failed],
        ];
    }

    public function test_incident_id_present_is_never_candidate(): void
    {
        $incident = $this->seedIncident();

        $this->seedCandidate([
            'ignore_reason' => 'own_outbound',
            'incident_id' => $incident->id,
            'processed_at' => '2026-06-01 10:05:00',
        ]);

        $this->assertSame(0, app(RetentionIgnoredEmailPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public function test_missing_processed_at_is_never_candidate(): void
    {
        $this->seedCandidate([
            'ignore_reason' => 'own_outbound',
            'processed_at' => null,
        ]);

        $summary = app(RetentionIgnoredEmailPruneService::class)->prune(dryRun: true);

        $this->assertSame(0, $summary->candidateCount);
        $this->assertSame(1, $summary->candidatesWithoutProcessedAt);
    }

    public function test_pending_inbound_outbox_excludes_candidate(): void
    {
        $message = $this->seedCandidate(['ignore_reason' => 'known_system_email']);

        OutboxEvent::query()->create([
            'idempotency_key' => 'retention.ignored-email.pending.'.$message->id,
            'event_type' => IncomingEmailOutboxWriter::EVENT_TYPE,
            'aggregate_type' => IncomingEmailOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $message->id,
            'payload' => ['incoming_email_message_id' => $message->id],
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
        ]);

        $this->assertSame(0, app(RetentionIgnoredEmailPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public function test_processing_inbound_outbox_excludes_candidate(): void
    {
        $message = $this->seedCandidate(['ignore_reason' => 'auto_responder']);

        OutboxEvent::query()->create([
            'idempotency_key' => 'retention.ignored-email.processing.'.$message->id,
            'event_type' => IncomingEmailOutboxWriter::EVENT_TYPE,
            'aggregate_type' => IncomingEmailOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $message->id,
            'payload' => ['incoming_email_message_id' => $message->id],
            'status' => OutboxEventStatus::Processing,
            'attempts' => 1,
            'available_at' => now(),
        ]);

        $this->assertSame(0, app(RetentionIgnoredEmailPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public function test_completed_outbox_allows_candidate_when_other_conditions_pass(): void
    {
        $message = $this->seedCandidate(['ignore_reason' => 'own_outbound']);

        OutboxEvent::query()->create([
            'idempotency_key' => 'retention.ignored-email.completed.'.$message->id,
            'event_type' => IncomingEmailOutboxWriter::EVENT_TYPE,
            'aggregate_type' => IncomingEmailOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $message->id,
            'payload' => ['incoming_email_message_id' => $message->id],
            'status' => OutboxEventStatus::Completed,
            'attempts' => 1,
            'available_at' => now()->subHour(),
            'processed_at' => now()->subHour(),
        ]);

        $this->assertSame(1, app(RetentionIgnoredEmailPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public function test_customer_email_match_without_order_id_is_not_retained_and_can_be_candidate(): void
    {
        $admin = User::factory()->create();
        Order::query()->create([
            'order_id' => 'ORD-'.uniqid(),
            'customer_email' => 'matched@example.com',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->seedCandidate([
            'from_email' => 'matched@example.com',
            'ignore_reason' => 'known_system_email',
            'processed_at' => '2026-06-01 10:05:00',
        ]);

        $this->assertSame(1, app(RetentionIgnoredEmailPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public function test_unknown_customer_is_excluded_by_default_allowlist(): void
    {
        $this->seedCandidate([
            'ignore_reason' => 'unknown_customer',
            'processed_at' => '2026-06-01 10:05:00',
        ]);

        $summary = app(RetentionIgnoredEmailPruneService::class)->prune(dryRun: true);

        $this->assertSame(0, $summary->candidateCount);
        $this->assertSame(1, $summary->predicateMatchCount);
        $this->assertSame(1, $summary->excludedUnknownCustomerCount);
    }

    public function test_boundary_exactly_at_ninety_days_is_not_candidate(): void
    {
        $this->seedCandidate([
            'ignore_reason' => 'own_outbound',
            'received_at' => '2026-06-23 12:00:00',
            'processed_at' => '2026-06-23 12:05:00',
        ]);

        $this->assertSame(0, app(RetentionIgnoredEmailPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public function test_one_second_before_ninety_day_boundary_is_candidate(): void
    {
        $this->seedCandidate([
            'ignore_reason' => 'own_outbound',
            'received_at' => '2026-06-23 11:59:59',
            'processed_at' => '2026-06-23 12:00:00',
        ]);

        $this->assertSame(1, app(RetentionIgnoredEmailPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public function test_future_received_at_is_never_candidate(): void
    {
        $this->seedCandidate([
            'ignore_reason' => 'own_outbound',
            'received_at' => '2026-12-01 10:00:00',
            'processed_at' => '2026-12-01 10:05:00',
        ]);

        $this->assertSame(0, app(RetentionIgnoredEmailPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public function test_dry_run_performs_no_writes(): void
    {
        $this->seedCandidate(['ignore_reason' => 'own_outbound']);

        $before = IncomingEmailMessage::query()->count();

        DB::enableQueryLog();

        $summary = app(RetentionIgnoredEmailPruneService::class)->prune(dryRun: true);

        $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $this->assertTrue($summary->dryRun);
        $this->assertSame(0, $summary->deletedCount);
        $this->assertSame($before, IncomingEmailMessage::query()->count());
        $this->assertStringNotContainsString('delete', strtolower($sql));
        $this->assertStringNotContainsString('update', strtolower($sql));
    }

    public function test_execute_mode_deletes_only_candidates(): void
    {
        $candidate = $this->seedCandidate(['ignore_reason' => 'own_outbound']);
        $this->seedCandidate([
            'ignore_reason' => 'unknown_customer',
            'provider_message_id' => 'unknown-'.uniqid(),
            'processed_at' => '2026-06-01 10:05:00',
        ]);

        $summary = app(RetentionIgnoredEmailPruneService::class)->prune(
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
                'ignore_reason' => 'known_system_email',
                'provider_message_id' => 'trash-'.$index,
                'processed_at' => '2026-06-01 10:05:00',
            ]);
        }

        $summary = app(RetentionIgnoredEmailPruneService::class)->prune(
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
        $candidate = $this->seedCandidate(['ignore_reason' => 'own_outbound']);
        $protected = $this->seedCandidate([
            'ignore_reason' => 'known_system_email',
            'provider_message_id' => 'protected-'.uniqid(),
        ]);

        $protected->update(['order_id' => $this->seedIncident()->order_id]);

        $summary = app(RetentionIgnoredEmailPruneService::class)->prune(
            dryRun: false,
            limit: 10,
        );

        $this->assertSame(1, $summary->deletedCount);
        $this->assertNull(IncomingEmailMessage::query()->find($candidate->id));
        $this->assertNotNull(IncomingEmailMessage::query()->find($protected->id));
    }

    public function test_incident_link_fk_excludes_candidate(): void
    {
        $message = $this->seedCandidate(['ignore_reason' => 'known_system_email']);
        $incident = $this->seedIncident();

        IncidentIncomingEmailLink::query()->create([
            'incident_id' => $incident->id,
            'incoming_email_message_id' => $message->id,
            'linked_at' => now(),
        ]);

        $this->assertSame(0, app(RetentionIgnoredEmailPruneService::class)->prune(dryRun: true)->candidateCount);
    }

    public function test_outgoing_reply_reference_excludes_candidate(): void
    {
        $message = $this->seedCandidate(['ignore_reason' => 'auto_responder']);
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
            'subject' => 'Re: Trash',
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

        $this->assertSame(0, app(RetentionIgnoredEmailPruneService::class)->prune(dryRun: true)->candidateCount);
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
            'from_email' => 'noise@example.com',
            'subject' => 'Ignored retention test',
            'preview' => 'Preview text',
            'status' => IncomingEmailMessageStatus::Ignored,
            'ignore_reason' => 'own_outbound',
            'received_at' => '2026-06-01 10:00:00',
            'processed_at' => '2026-06-01 10:05:00',
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
