<?php

namespace Tests\Unit\Retention;

use App\Enums\IncomingEmailMessageStatus;
use App\Enums\OutgoingEmailMessageStatus;
use App\Models\Incident;
use App\Models\IncidentIncomingEmailLink;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\OutgoingEmailMessage;
use App\Models\User;
use App\Services\Retention\RetentionHistoricalGmailNoiseInspectionService;
use App\Services\Retention\RetentionHistoricalUnknownCustomerInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetentionHistoricalUnknownCustomerInspectionServiceTest extends TestCase
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
        $this->seedCandidate();

        $before = IncomingEmailMessage::query()->count();

        app(RetentionHistoricalUnknownCustomerInspectionService::class)->inspect();

        $this->assertSame($before, IncomingEmailMessage::query()->count());
        $this->assertSame($before, (int) DB::table('incoming_email_messages')->count());
    }

    public function test_candidate_matches_status_and_ignore_reason(): void
    {
        $service = app(RetentionHistoricalUnknownCustomerInspectionService::class);

        $this->seedCandidate([
            'received_at' => '2026-06-30 23:59:59',
            'provider_message_id' => 'candidate-eligible',
        ]);

        $summary = $service->inspect();

        $this->assertSame(1, $summary->candidateCount);
        $this->assertSame('2026-07-01 00:00:00', $summary->receivedAtCutoff);
        $this->assertSame(['unknown_customer' => 1], $summary->candidatesByIgnoreReason);
    }

    public function test_received_at_cutoff_includes_june_30_and_excludes_july_1(): void
    {
        $service = app(RetentionHistoricalUnknownCustomerInspectionService::class);

        $this->seedCandidate([
            'received_at' => '2026-06-30 23:59:59',
            'provider_message_id' => 'june-30-eligible',
        ]);

        $this->seedCandidate([
            'received_at' => '2026-07-01 00:00:00',
            'provider_message_id' => 'july-1-excluded',
        ]);

        $summary = $service->inspect();

        $this->assertSame(1, $summary->candidateCount);
        $this->assertSame(
            '2026-07-01 00:00:00',
            $service->receivedAtCutoff()->toDateTimeString(),
        );
    }

    public function test_created_at_is_not_used_for_historical_cutoff(): void
    {
        $included = $this->seedCandidate([
            'received_at' => '2026-06-15 10:00:00',
            'provider_message_id' => 'old-received-new-created',
        ]);
        $included->forceFill(['created_at' => '2026-08-10 12:00:00'])->save();

        $excluded = $this->seedCandidate([
            'received_at' => '2026-08-10 12:00:00',
            'provider_message_id' => 'new-received-old-created',
        ]);
        $excluded->forceFill(['created_at' => '2026-06-15 10:00:00'])->save();

        $summary = app(RetentionHistoricalUnknownCustomerInspectionService::class)->inspect();

        $this->assertSame(1, $summary->candidateCount);
        $this->assertContains($included->id, $summary->sampleCandidateIds);
    }

    public function test_needs_review_unknown_customer_is_excluded_from_candidates(): void
    {
        $this->seedCandidate([
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'received_at' => '2026-06-15 10:00:00',
            'provider_message_id' => 'needs-review',
        ]);

        $summary = app(RetentionHistoricalUnknownCustomerInspectionService::class)->inspect();

        $this->assertSame(0, $summary->candidateCount);
        $this->assertSame(1, $summary->excludedNeedsReviewUnknownCustomerCount);
    }

    public function test_non_unknown_customer_ignore_reason_is_excluded(): void
    {
        $this->seedCandidate([
            'ignore_reason' => 'promotions',
            'received_at' => '2026-06-15 10:00:00',
            'provider_message_id' => 'promo',
        ]);

        $summary = app(RetentionHistoricalUnknownCustomerInspectionService::class)->inspect();

        $this->assertSame(0, $summary->candidateCount);
    }

    public function test_incident_order_link_and_reply_rows_are_excluded(): void
    {
        $service = app(RetentionHistoricalUnknownCustomerInspectionService::class);

        $withIncident = $this->seedCandidate(['provider_message_id' => 'with-incident']);
        $withIncident->update(['incident_id' => $this->seedIncident()->id]);

        $withOrder = $this->seedCandidate(['provider_message_id' => 'with-order']);
        $order = Order::query()->create([
            'order_id' => 'ORD-UC-1',
            'status' => 'active',
            'created_by' => User::factory()->create()->id,
        ]);
        $withOrder->update(['order_id' => $order->id]);

        $linked = $this->seedCandidate(['provider_message_id' => 'linked']);
        $incident = $this->seedIncident();
        IncidentIncomingEmailLink::query()->create([
            'incident_id' => $incident->id,
            'incoming_email_message_id' => $linked->id,
            'linked_at' => now(),
        ]);

        $replied = $this->seedCandidate(['provider_message_id' => 'replied']);
        $admin = User::factory()->create();
        OutgoingEmailMessage::query()->create([
            'in_reply_to_incoming_email_message_id' => $replied->id,
            'incident_id' => $incident->id,
            'order_id' => $order->id,
            'mailbox' => 'support@radiumbox.com',
            'to_email' => 'customer@example.com',
            'subject' => 'Reply',
            'body_html' => '<p>Reply</p>',
            'body_text' => 'Reply body',
            'preview' => 'Reply',
            'thread_id' => 'thr-reply',
            'provider' => 'gmail',
            'provider_message_id' => 'gmail-out-reply',
            'sent_by_user_id' => $admin->id,
            'sent_at' => now(),
            'status' => OutgoingEmailMessageStatus::Sent,
        ]);

        $this->seedCandidate(['provider_message_id' => 'valid-candidate']);

        $summary = $service->inspect();

        $this->assertSame(1, $summary->candidateCount);
        $this->assertSame(1, $summary->excludedUnknownCustomerWithIncidentId);
        $this->assertSame(1, $summary->excludedUnknownCustomerWithOrderId);
        $this->assertSame(1, $summary->excludedUnknownCustomerWithLinkFk);
        $this->assertSame(1, $summary->excludedUnknownCustomerWithOutgoingReplyFk);
        $this->assertSame(0, $summary->candidatesWithIncidentId);
        $this->assertSame(0, $summary->candidatesWithOrderId);
        $this->assertSame(0, $summary->candidatesWithLinkFk);
        $this->assertSame(0, $summary->candidatesWithOutgoingReplyFk);
    }

    public function test_payload_estimate_aggregates_without_loading_full_payload_in_summary(): void
    {
        $this->seedCandidate([
            'preview' => str_repeat('x', 500),
            'headers' => str_repeat('h', 1000),
            'raw_payload' => ['body' => str_repeat('b', 200)],
            'provider_message_id' => 'payload-heavy',
        ]);

        $summary = app(RetentionHistoricalUnknownCustomerInspectionService::class)->inspect();

        $this->assertGreaterThan(1500, $summary->estimatedPayloadBytes);
        $this->assertArrayNotHasKey('raw_payload', $summary->sampleCandidateMetadata[0] ?? []);
        $this->assertArrayNotHasKey('headers', $summary->sampleCandidateMetadata[0] ?? []);
    }

    public function test_sample_metadata_uses_safe_columns_only(): void
    {
        $this->seedCandidate([
            'subject' => 'Safe subject',
            'from_email' => 'sender@example.com',
            'provider_message_id' => 'safe-meta',
        ]);

        $summary = app(RetentionHistoricalUnknownCustomerInspectionService::class)->inspect();

        $this->assertNotEmpty($summary->sampleCandidateMetadata);
        $sample = $summary->sampleCandidateMetadata[0];

        $this->assertSame('Safe subject', $sample['subject']);
        $this->assertSame('sender@example.com', $sample['from_email']);
        $this->assertArrayHasKey('id', $sample);
        $this->assertArrayNotHasKey('raw_payload', $sample);
        $this->assertArrayNotHasKey('headers', $sample);
    }

    public function test_gmail_noise_inspection_is_unaffected(): void
    {
        $this->seedCandidate([
            'ignore_reason' => 'unknown_customer',
            'received_at' => '2026-05-01 10:00:00',
        ]);

        $noiseSummary = app(RetentionHistoricalGmailNoiseInspectionService::class)->inspect();

        $this->assertSame(0, $noiseSummary->candidateCount);
        $this->assertSame(1, $noiseSummary->excludedUnknownCustomerCount);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedCandidate(array $overrides = []): IncomingEmailMessage
    {
        $receivedAt = $overrides['received_at'] ?? '2026-06-15 10:00:00';
        unset($overrides['received_at']);

        $message = IncomingEmailMessage::query()->create(array_merge([
            'mailbox' => 'mail@radiumbox.com',
            'provider' => 'gmail',
            'provider_message_id' => 'uc-'.uniqid(),
            'from_email' => 'unknown@example.com',
            'subject' => 'Unknown customer mail',
            'preview' => 'Preview',
            'status' => IncomingEmailMessageStatus::Ignored,
            'ignore_reason' => 'unknown_customer',
            'received_at' => $receivedAt,
            'attachment_count' => 0,
            'raw_payload' => ['fixture' => true],
        ], $overrides));

        DB::table('incoming_email_messages')->where('id', $message->id)->update([
            'created_at' => Carbon::parse($receivedAt)->addDays(2000),
        ]);

        return $message->fresh();
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
            'reference_no' => 'SC-UC-'.uniqid(),
            'category' => 'General',
            'source' => 'email',
            'title' => 'Unknown customer test incident',
            'description' => 'Test incident.',
            'status' => 'open',
            'created_by' => $actor->id,
        ]);
    }
}
