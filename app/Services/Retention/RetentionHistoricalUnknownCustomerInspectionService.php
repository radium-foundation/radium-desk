<?php

namespace App\Services\Retention;

use App\Data\Retention\RetentionHistoricalUnknownCustomerInspectionSummary;
use App\Enums\IncomingEmailMessageStatus;
use App\Models\GmailMailboxSyncState;
use App\Models\IncomingEmailMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RetentionHistoricalUnknownCustomerInspectionService
{
    /**
     * Read-only inspection for historical ignored unknown_customer email rows.
     *
     * Uses fixed received_at cutoff (not created_at). Never writes, updates, or deletes.
     */
    public function inspect(?Carbon $at = null): RetentionHistoricalUnknownCustomerInspectionSummary
    {
        $at ??= now();
        $cutoff = $this->receivedAtCutoff();
        $sampleLimit = max(1, (int) config('retention.historical_unknown_customer.sample_id_limit', 10));
        $metadataLimit = max(1, (int) config('retention.historical_unknown_customer.sample_metadata_limit', 5));

        if (! Schema::hasTable('incoming_email_messages')) {
            return $this->emptySummary($at, $cutoff);
        }

        $candidateQuery = $this->candidateQuery();
        $candidateCount = (clone $candidateQuery)->count();

        $bounds = (clone $candidateQuery)
            ->selectRaw('MIN(received_at) as oldest_received_at, MAX(received_at) as newest_received_at')
            ->first();

        $candidatesByIgnoreReason = (clone $candidateQuery)
            ->selectRaw('ignore_reason, COUNT(*) as aggregate_count')
            ->groupBy('ignore_reason')
            ->orderByDesc('aggregate_count')
            ->pluck('aggregate_count', 'ignore_reason')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $candidatesByReceivedMonth = (clone $candidateQuery)
            ->selectRaw($this->receivedMonthExpression().' as month_key, COUNT(*) as aggregate_count')
            ->groupBy('month_key')
            ->orderBy('month_key')
            ->pluck('aggregate_count', 'month_key')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $candidatesByYear = (clone $candidateQuery)
            ->selectRaw($this->receivedYearExpression().' as year_key, COUNT(*) as aggregate_count')
            ->groupBy('year_key')
            ->orderBy('year_key')
            ->pluck('aggregate_count', 'year_key')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $estimatedPayloadBytes = (int) (clone $candidateQuery)
            ->selectRaw('COALESCE(SUM('.$this->payloadLengthExpression().'), 0) as aggregate_bytes')
            ->value('aggregate_bytes');

        $sampleCandidateIds = (clone $candidateQuery)
            ->orderBy('id')
            ->limit($sampleLimit)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $sampleCandidateMetadata = (clone $candidateQuery)
            ->orderBy('id')
            ->limit($metadataLimit)
            ->get([
                'id',
                'received_at',
                'created_at',
                'provider_message_id',
                'rfc_message_id',
                'subject',
                'from_email',
                'ignore_reason',
                'status',
            ])
            ->map(fn (IncomingEmailMessage $message): array => [
                'id' => $message->id,
                'received_at' => $message->received_at?->toDateTimeString(),
                'created_at' => $message->created_at?->toDateTimeString(),
                'provider_message_id' => $message->provider_message_id,
                'rfc_message_id' => $message->rfc_message_id,
                'subject' => $message->subject,
                'from_email' => $message->from_email,
                'ignore_reason' => $message->ignore_reason,
                'status' => $message->status?->value ?? (string) $message->status,
            ])
            ->values()
            ->all();

        return new RetentionHistoricalUnknownCustomerInspectionSummary(
            inspectedAt: $at,
            tableTotalCount: IncomingEmailMessage::query()->count(),
            receivedAtCutoff: $cutoff->toDateTimeString(),
            candidateCount: $candidateCount,
            estimatedPayloadBytes: $estimatedPayloadBytes,
            oldestCandidateReceivedAt: $this->formatDateTime($bounds?->oldest_received_at),
            newestCandidateReceivedAt: $this->formatDateTime($bounds?->newest_received_at),
            candidatesByIgnoreReason: $candidatesByIgnoreReason,
            candidatesByReceivedMonth: $candidatesByReceivedMonth,
            candidatesByYear: $candidatesByYear,
            sampleCandidateIds: $sampleCandidateIds,
            sampleCandidateMetadata: $sampleCandidateMetadata,
            unknownCustomerIgnoredTotal: $this->unknownCustomerIgnoredTotal(),
            postCutoffUnknownCustomerIgnoredCount: $this->postCutoffUnknownCustomerIgnoredCount($cutoff),
            excludedNeedsReviewUnknownCustomerCount: $this->excludedNeedsReviewUnknownCustomerCount(),
            excludedUnknownCustomerWithIncidentId: $this->excludedUnknownCustomerWithIncidentId(),
            excludedUnknownCustomerWithOrderId: $this->excludedUnknownCustomerWithOrderId(),
            excludedUnknownCustomerWithLinkFk: $this->excludedUnknownCustomerWithLinkFk(),
            excludedUnknownCustomerWithOutgoingReplyFk: $this->excludedUnknownCustomerWithOutgoingReplyFk(),
            candidatesWithIncidentId: $this->countCandidatesWithIncidentId($candidateQuery),
            candidatesWithOrderId: $this->countCandidatesWithOrderId($candidateQuery),
            candidatesWithLinkFk: $this->countCandidatesWithLinkFk($candidateQuery),
            candidatesWithOutgoingReplyFk: $this->countCandidatesWithOutgoingReplyFk($candidateQuery),
            gmailSyncStates: $this->gmailSyncStates(),
        );
    }

    /**
     * @return Builder<IncomingEmailMessage>
     */
    public function candidateQuery(): Builder
    {
        $cutoff = $this->receivedAtCutoff();

        return IncomingEmailMessage::query()
            ->where('status', IncomingEmailMessageStatus::Ignored)
            ->where('ignore_reason', $this->ignoreReason())
            ->where('received_at', '<', $cutoff)
            ->whereNull('incident_id')
            ->whereNull('order_id')
            ->whereNotExists(function ($subquery): void {
                $subquery->select(DB::raw('1'))
                    ->from('incident_incoming_email_links')
                    ->whereColumn(
                        'incident_incoming_email_links.incoming_email_message_id',
                        'incoming_email_messages.id',
                    );
            })
            ->whereNotExists(function ($subquery): void {
                $subquery->select(DB::raw('1'))
                    ->from('outgoing_email_messages')
                    ->whereColumn(
                        'outgoing_email_messages.in_reply_to_incoming_email_message_id',
                        'incoming_email_messages.id',
                    );
            });
    }

    public function receivedAtCutoff(): Carbon
    {
        $configured = config('retention.historical_unknown_customer.received_at_cutoff', '2026-07-01 00:00:00');

        return Carbon::parse(is_string($configured) ? $configured : '2026-07-01 00:00:00');
    }

    public function ignoreReason(): string
    {
        $reason = config('retention.historical_unknown_customer.ignore_reason', 'unknown_customer');

        return is_string($reason) && $reason !== '' ? $reason : 'unknown_customer';
    }

    /**
     * @param  Builder<IncomingEmailMessage>  $candidateQuery
     */
    public function countCandidatesWithIncidentId(Builder $candidateQuery): int
    {
        return (clone $candidateQuery)->whereNotNull('incident_id')->count();
    }

    /**
     * @param  Builder<IncomingEmailMessage>  $candidateQuery
     */
    public function countCandidatesWithOrderId(Builder $candidateQuery): int
    {
        return (clone $candidateQuery)->whereNotNull('order_id')->count();
    }

    /**
     * @param  Builder<IncomingEmailMessage>  $candidateQuery
     */
    public function countCandidatesWithLinkFk(Builder $candidateQuery): int
    {
        return (clone $candidateQuery)
            ->whereExists(function ($subquery): void {
                $subquery->select(DB::raw('1'))
                    ->from('incident_incoming_email_links')
                    ->whereColumn(
                        'incident_incoming_email_links.incoming_email_message_id',
                        'incoming_email_messages.id',
                    );
            })
            ->count();
    }

    /**
     * @param  Builder<IncomingEmailMessage>  $candidateQuery
     */
    public function countCandidatesWithOutgoingReplyFk(Builder $candidateQuery): int
    {
        return (clone $candidateQuery)
            ->whereExists(function ($subquery): void {
                $subquery->select(DB::raw('1'))
                    ->from('outgoing_email_messages')
                    ->whereColumn(
                        'outgoing_email_messages.in_reply_to_incoming_email_message_id',
                        'incoming_email_messages.id',
                    );
            })
            ->count();
    }

    private function unknownCustomerIgnoredTotal(): int
    {
        return IncomingEmailMessage::query()
            ->where('status', IncomingEmailMessageStatus::Ignored)
            ->where('ignore_reason', $this->ignoreReason())
            ->count();
    }

    private function postCutoffUnknownCustomerIgnoredCount(Carbon $cutoff): int
    {
        return IncomingEmailMessage::query()
            ->where('status', IncomingEmailMessageStatus::Ignored)
            ->where('ignore_reason', $this->ignoreReason())
            ->where('received_at', '>=', $cutoff)
            ->count();
    }

    private function excludedNeedsReviewUnknownCustomerCount(): int
    {
        return IncomingEmailMessage::query()
            ->where('ignore_reason', $this->ignoreReason())
            ->where('status', IncomingEmailMessageStatus::NeedsReview)
            ->count();
    }

    private function excludedUnknownCustomerWithIncidentId(): int
    {
        return IncomingEmailMessage::query()
            ->where('ignore_reason', $this->ignoreReason())
            ->whereNotNull('incident_id')
            ->count();
    }

    private function excludedUnknownCustomerWithOrderId(): int
    {
        return IncomingEmailMessage::query()
            ->where('ignore_reason', $this->ignoreReason())
            ->whereNotNull('order_id')
            ->count();
    }

    private function excludedUnknownCustomerWithLinkFk(): int
    {
        return IncomingEmailMessage::query()
            ->where('ignore_reason', $this->ignoreReason())
            ->whereExists(function ($subquery): void {
                $subquery->select(DB::raw('1'))
                    ->from('incident_incoming_email_links')
                    ->whereColumn(
                        'incident_incoming_email_links.incoming_email_message_id',
                        'incoming_email_messages.id',
                    );
            })
            ->count();
    }

    private function excludedUnknownCustomerWithOutgoingReplyFk(): int
    {
        return IncomingEmailMessage::query()
            ->where('ignore_reason', $this->ignoreReason())
            ->whereExists(function ($subquery): void {
                $subquery->select(DB::raw('1'))
                    ->from('outgoing_email_messages')
                    ->whereColumn(
                        'outgoing_email_messages.in_reply_to_incoming_email_message_id',
                        'incoming_email_messages.id',
                    );
            })
            ->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gmailSyncStates(): array
    {
        if (! Schema::hasTable('gmail_mailbox_sync_states')) {
            return [];
        }

        return GmailMailboxSyncState::query()
            ->orderBy('mailbox')
            ->get(['mailbox', 'history_id', 'profile_history_id', 'baselined_at', 'last_synced_at', 'oauth_status', 'last_error'])
            ->map(fn (GmailMailboxSyncState $state): array => [
                'mailbox' => $state->mailbox,
                'history_id' => $state->history_id,
                'profile_history_id' => $state->profile_history_id,
                'baselined_at' => $state->baselined_at?->toDateTimeString(),
                'last_synced_at' => $state->last_synced_at?->toDateTimeString(),
                'oauth_status' => $state->oauth_status,
                'last_error' => $state->last_error,
            ])
            ->values()
            ->all();
    }

    private function payloadLengthExpression(): string
    {
        return implode(' + ', [
            'COALESCE('.($this->lengthFunction()).'(COALESCE(raw_payload, "")), 0)',
            'COALESCE('.($this->lengthFunction()).'(COALESCE(headers, "")), 0)',
            'COALESCE('.($this->lengthFunction()).'(COALESCE(labels, "")), 0)',
            'COALESCE('.($this->lengthFunction()).'(COALESCE(preview, "")), 0)',
            'COALESCE('.($this->lengthFunction()).'(COALESCE(subject, "")), 0)',
            'COALESCE('.($this->lengthFunction()).'(COALESCE(to_emails, "")), 0)',
        ]);
    }

    private function lengthFunction(): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';
    }

    private function receivedMonthExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => 'strftime("%Y-%m", received_at)',
            default => 'DATE_FORMAT(received_at, "%Y-%m")',
        };
    }

    private function receivedYearExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => 'strftime("%Y", received_at)',
            default => 'YEAR(received_at)',
        };
    }

    private function emptySummary(Carbon $at, Carbon $cutoff): RetentionHistoricalUnknownCustomerInspectionSummary
    {
        return new RetentionHistoricalUnknownCustomerInspectionSummary(
            inspectedAt: $at,
            tableTotalCount: 0,
            receivedAtCutoff: $cutoff->toDateTimeString(),
            candidateCount: 0,
            estimatedPayloadBytes: 0,
            oldestCandidateReceivedAt: null,
            newestCandidateReceivedAt: null,
            candidatesByIgnoreReason: [],
            candidatesByReceivedMonth: [],
            candidatesByYear: [],
            sampleCandidateIds: [],
            sampleCandidateMetadata: [],
            unknownCustomerIgnoredTotal: 0,
            postCutoffUnknownCustomerIgnoredCount: 0,
            excludedNeedsReviewUnknownCustomerCount: 0,
            excludedUnknownCustomerWithIncidentId: 0,
            excludedUnknownCustomerWithOrderId: 0,
            excludedUnknownCustomerWithLinkFk: 0,
            excludedUnknownCustomerWithOutgoingReplyFk: 0,
            candidatesWithIncidentId: 0,
            candidatesWithOrderId: 0,
            candidatesWithLinkFk: 0,
            candidatesWithOutgoingReplyFk: 0,
            gmailSyncStates: [],
        );
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->toDateTimeString();
        }

        return Carbon::parse((string) $value)->toDateTimeString();
    }
}
