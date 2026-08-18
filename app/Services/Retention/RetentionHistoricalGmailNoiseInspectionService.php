<?php

namespace App\Services\Retention;

use App\Enums\IncomingEmailMessageStatus;
use App\Models\IncomingEmailMessage;
use App\Data\Retention\RetentionHistoricalGmailNoiseSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RetentionHistoricalGmailNoiseInspectionService
{
    /**
     * Read-only inspection for pre-cutoff historical Gmail noise rows.
     *
     * Uses received_at (not created_at) for the historical cutoff.
     */
    public function inspect(?Carbon $at = null): RetentionHistoricalGmailNoiseSummary
    {
        $at ??= now();
        $cutoff = $this->receivedAtCutoff();
        $sampleLimit = max(1, (int) config('retention.historical_gmail_noise.sample_id_limit', 10));

        if (! Schema::hasTable('incoming_email_messages')) {
            return $this->emptySummary($at, $cutoff);
        }

        $candidateQuery = $this->candidateQuery($cutoff);
        $candidateCount = (clone $candidateQuery)->count();

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

        return new RetentionHistoricalGmailNoiseSummary(
            inspectedAt: $at,
            receivedAtCutoff: $cutoff->toDateTimeString(),
            candidateCount: $candidateCount,
            candidatesByIgnoreReason: $candidatesByIgnoreReason,
            candidatesByReceivedMonth: $candidatesByReceivedMonth,
            estimatedPayloadBytes: $estimatedPayloadBytes,
            sampleCandidateIds: $sampleCandidateIds,
            candidatesWithIncidentId: $this->countCandidatesWithIncidentId($candidateQuery),
            candidatesWithOrderId: $this->countCandidatesWithOrderId($candidateQuery),
            candidatesWithLinkFk: $this->countCandidatesWithLinkFk($candidateQuery),
            candidatesWithOutgoingReplyFk: $this->countCandidatesWithOutgoingReplyFk($candidateQuery),
            excludedUnknownCustomerCount: $this->excludedUnknownCustomerCount($cutoff),
            excludedExplicitMessageIdCount: $this->excludedExplicitMessageIdCount($cutoff),
        );
    }

    /**
     * @return Builder<IncomingEmailMessage>
     */
    public function candidateQuery(Carbon $cutoff): Builder
    {
        $query = IncomingEmailMessage::query()
            ->where('received_at', '<', $cutoff)
            ->where('status', IncomingEmailMessageStatus::Ignored)
            ->whereNull('incident_id')
            ->whereNull('order_id')
            ->whereIn('ignore_reason', $this->approvedIgnoreReasons())
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

        $excludedIds = $this->excludedMessageIds();

        if ($excludedIds !== []) {
            $query->whereNotIn('incoming_email_messages.id', $excludedIds);
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    public function approvedIgnoreReasons(): array
    {
        $reasons = config('retention.historical_gmail_noise.ignore_reasons', []);

        return array_values(array_filter(
            is_array($reasons) ? $reasons : [],
            static fn (mixed $reason): bool => is_string($reason) && $reason !== '',
        ));
    }

    /**
     * @return list<int>
     */
    public function excludedMessageIds(): array
    {
        $ids = config('retention.historical_gmail_noise.excluded_message_ids', []);

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            array_filter($ids, static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0),
        )));
    }

    public function receivedAtCutoff(): Carbon
    {
        $configured = config('retention.historical_gmail_noise.received_at_cutoff', '2026-07-01 00:00:00');

        return Carbon::parse(is_string($configured) ? $configured : '2026-07-01 00:00:00');
    }

    private function payloadLengthExpression(): string
    {
        return implode(' + ', [
            'COALESCE(LENGTH(COALESCE(raw_payload, "")), 0)',
            'COALESCE(LENGTH(COALESCE(headers, "")), 0)',
            'COALESCE(LENGTH(COALESCE(labels, "")), 0)',
            'COALESCE(LENGTH(COALESCE(preview, "")), 0)',
            'COALESCE(LENGTH(COALESCE(subject, "")), 0)',
            'COALESCE(LENGTH(COALESCE(to_emails, "")), 0)',
        ]);
    }

    private function receivedMonthExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => 'strftime("%Y-%m", received_at)',
            default => 'DATE_FORMAT(received_at, "%Y-%m")',
        };
    }

    /**
     * @param  Builder<IncomingEmailMessage>  $candidateQuery
     */
    private function countCandidatesWithIncidentId(Builder $candidateQuery): int
    {
        return (clone $candidateQuery)->whereNotNull('incident_id')->count();
    }

    /**
     * @param  Builder<IncomingEmailMessage>  $candidateQuery
     */
    private function countCandidatesWithOrderId(Builder $candidateQuery): int
    {
        return (clone $candidateQuery)->whereNotNull('order_id')->count();
    }

    /**
     * @param  Builder<IncomingEmailMessage>  $candidateQuery
     */
    private function countCandidatesWithLinkFk(Builder $candidateQuery): int
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
    private function countCandidatesWithOutgoingReplyFk(Builder $candidateQuery): int
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

    private function excludedUnknownCustomerCount(Carbon $cutoff): int
    {
        return IncomingEmailMessage::query()
            ->where('received_at', '<', $cutoff)
            ->where('status', IncomingEmailMessageStatus::Ignored)
            ->where('ignore_reason', 'unknown_customer')
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
            })
            ->count();
    }

    private function excludedExplicitMessageIdCount(Carbon $cutoff): int
    {
        $ids = $this->excludedMessageIds();

        if ($ids === []) {
            return 0;
        }

        return IncomingEmailMessage::query()
            ->whereIn('id', $ids)
            ->where('received_at', '<', $cutoff)
            ->count();
    }

    private function emptySummary(Carbon $at, Carbon $cutoff): RetentionHistoricalGmailNoiseSummary
    {
        return new RetentionHistoricalGmailNoiseSummary(
            inspectedAt: $at,
            receivedAtCutoff: $cutoff->toDateTimeString(),
            candidateCount: 0,
            candidatesByIgnoreReason: [],
            candidatesByReceivedMonth: [],
            estimatedPayloadBytes: 0,
            sampleCandidateIds: [],
            candidatesWithIncidentId: 0,
            candidatesWithOrderId: 0,
            candidatesWithLinkFk: 0,
            candidatesWithOutgoingReplyFk: 0,
            excludedUnknownCustomerCount: 0,
            excludedExplicitMessageIdCount: 0,
        );
    }
}
