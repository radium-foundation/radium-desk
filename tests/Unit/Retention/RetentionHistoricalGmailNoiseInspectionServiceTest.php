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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RetentionHistoricalGmailNoiseInspectionServiceTest extends TestCase
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
        $this->seedCandidate(['ignore_reason' => 'promotions']);

        $before = IncomingEmailMessage::query()->count();

        app(RetentionHistoricalGmailNoiseInspectionService::class)->inspect();

        $this->assertSame($before, IncomingEmailMessage::query()->count());
    }

    #[DataProvider('approvedIgnoreReasonProvider')]
    public function test_each_approved_ignore_reason_is_included(string $reason): void
    {
        $this->seedCandidate(['ignore_reason' => $reason, 'provider_message_id' => 'approved-'.$reason]);

        $summary = app(RetentionHistoricalGmailNoiseInspectionService::class)->inspect();

        $this->assertSame(1, $summary->candidateCount);
        $this->assertSame(1, $summary->candidatesByIgnoreReason[$reason] ?? 0);
    }

    public static function approvedIgnoreReasonProvider(): array
    {
        return [
            ['promotions'],
            ['social'],
            ['spam'],
            ['trash'],
            ['newsletter_or_marketing'],
            ['known_system_email'],
            ['auto_responder'],
            ['bounce_or_delivery_subsystem'],
            ['own_outbound'],
        ];
    }

    public function test_linked_email_is_excluded(): void
    {
        $message = $this->seedCandidate(['ignore_reason' => 'promotions']);
        $incident = $this->seedIncident();

        $message->update([
            'status' => IncomingEmailMessageStatus::Linked,
            'incident_id' => $incident->id,
        ]);

        IncidentIncomingEmailLink::query()->create([
            'incident_id' => $incident->id,
            'incoming_email_message_id' => $message->id,
            'linked_at' => now(),
        ]);

        $summary = app(RetentionHistoricalGmailNoiseInspectionService::class)->inspect();

        $this->assertSame(0, $summary->candidateCount);
    }

    public function test_historical_customer_is_excluded(): void
    {
        $this->seedCandidate([
            'status' => IncomingEmailMessageStatus::HistoricalCustomer,
            'ignore_reason' => null,
        ]);

        $summary = app(RetentionHistoricalGmailNoiseInspectionService::class)->inspect();

        $this->assertSame(0, $summary->candidateCount);
    }

    public function test_needs_review_is_excluded(): void
    {
        $this->seedCandidate([
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'ignore_reason' => null,
        ]);

        $summary = app(RetentionHistoricalGmailNoiseInspectionService::class)->inspect();

        $this->assertSame(0, $summary->candidateCount);
    }

    public function test_unknown_customer_is_excluded(): void
    {
        $this->seedCandidate(['ignore_reason' => 'unknown_customer']);

        $summary = app(RetentionHistoricalGmailNoiseInspectionService::class)->inspect();

        $this->assertSame(0, $summary->candidateCount);
        $this->assertSame(1, $summary->excludedUnknownCustomerCount);
    }

    public function test_explicit_message_id_244287_is_excluded(): void
    {
        $this->seedCandidate([
            'id' => 244287,
            'status' => IncomingEmailMessageStatus::Received,
            'ignore_reason' => null,
            'received_at' => '2023-07-24 16:02:56',
            'from_email' => 'renewal@radiumbox.com',
        ]);

        $summary = app(RetentionHistoricalGmailNoiseInspectionService::class)->inspect();

        $this->assertSame(0, $summary->candidateCount);
        $this->assertSame(1, $summary->excludedExplicitMessageIdCount);
    }

    public function test_incident_incoming_email_link_excludes_candidate(): void
    {
        $message = $this->seedCandidate(['ignore_reason' => 'spam']);
        $incident = $this->seedIncident();

        IncidentIncomingEmailLink::query()->create([
            'incident_id' => $incident->id,
            'incoming_email_message_id' => $message->id,
            'linked_at' => now(),
        ]);

        $summary = app(RetentionHistoricalGmailNoiseInspectionService::class)->inspect();

        $this->assertSame(0, $summary->candidateCount);
    }

    public function test_outgoing_reply_reference_excludes_candidate(): void
    {
        $message = $this->seedCandidate(['ignore_reason' => 'trash']);
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

        $summary = app(RetentionHistoricalGmailNoiseInspectionService::class)->inspect();

        $this->assertSame(0, $summary->candidateCount);
    }

    public function test_received_at_cutoff_is_enforced(): void
    {
        $this->seedCandidate([
            'ignore_reason' => 'promotions',
            'received_at' => '2026-07-01 00:00:00',
            'provider_message_id' => 'after-cutoff',
        ]);

        $summary = app(RetentionHistoricalGmailNoiseInspectionService::class)->inspect();

        $this->assertSame(0, $summary->candidateCount);
    }

    public function test_created_at_is_not_used_for_historical_cutoff(): void
    {
        $included = $this->seedCandidate([
            'ignore_reason' => 'social',
            'received_at' => '2026-05-01 10:00:00',
            'provider_message_id' => 'old-received-new-created',
        ]);
        $included->forceFill(['created_at' => '2026-08-10 12:00:00'])->save();

        $excluded = $this->seedCandidate([
            'ignore_reason' => 'social',
            'received_at' => '2026-08-10 12:00:00',
            'provider_message_id' => 'new-received-old-created',
        ]);
        $excluded->forceFill(['created_at' => '2026-05-01 10:00:00'])->save();

        $summary = app(RetentionHistoricalGmailNoiseInspectionService::class)->inspect();

        $this->assertSame(1, $summary->candidateCount);
        $this->assertContains($included->id, $summary->sampleCandidateIds);
    }

    public function test_safety_counts_on_candidates_are_zero(): void
    {
        $this->seedCandidate(['ignore_reason' => 'promotions']);

        $summary = app(RetentionHistoricalGmailNoiseInspectionService::class)->inspect();

        $this->assertSame(1, $summary->candidateCount);
        $this->assertSame(0, $summary->candidatesWithIncidentId);
        $this->assertSame(0, $summary->candidatesWithOrderId);
        $this->assertSame(0, $summary->candidatesWithLinkFk);
        $this->assertSame(0, $summary->candidatesWithOutgoingReplyFk);
    }

    public function test_payload_estimate_and_month_breakdown_are_reported(): void
    {
        $this->seedCandidate([
            'ignore_reason' => 'promotions',
            'received_at' => '2026-03-15 08:00:00',
            'raw_payload' => ['body' => str_repeat('x', 500)],
            'headers' => ['X-Test' => 'value'],
        ]);

        $summary = app(RetentionHistoricalGmailNoiseInspectionService::class)->inspect();

        $this->assertGreaterThan(0, $summary->estimatedPayloadBytes);
        $this->assertSame(1, $summary->candidatesByReceivedMonth['2026-03'] ?? 0);
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
            'subject' => 'Historical noise',
            'preview' => 'Preview text',
            'status' => IncomingEmailMessageStatus::Ignored,
            'ignore_reason' => 'promotions',
            'received_at' => '2026-05-01 10:00:00',
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
