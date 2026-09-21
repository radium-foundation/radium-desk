<?php

namespace App\Services\Retention;

use App\Enums\IncomingEmailMessageStatus;
use App\Enums\OutboxEventStatus;
use App\Models\IncomingEmailMessage;
use App\Data\Retention\RetentionIgnoredEmailPruneSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RetentionIgnoredEmailInspectionService
{
    /**
     * Read-only inspection for rolling ignored-email retention candidates.
     *
     * Uses received_at (not created_at) for the age cutoff.
     */
    public function inspect(?Carbon $at = null, ?string $manifestPath = null): RetentionIgnoredEmailPruneSummary
    {
        $at ??= now();
        $days = $this->ignoredEmailDays();
        $cutoff = $this->receivedAtCutoff($at);
        $sampleLimit = max(1, (int) config('retention.ignored_email.sample_id_limit', 10));

        if (! Schema::hasTable('incoming_email_messages')) {
            return $this->emptySummary($at, true, $days, $cutoff, $manifestPath);
        }

        $predicateQuery = $this->predicateQuery($cutoff);
        $candidateQuery = $this->candidateQuery($cutoff);

        $predicateMatchCount = (clone $predicateQuery)->count();
        $candidateCount = (clone $candidateQuery)->count();

        $candidatesByIgnoreReason = (clone $candidateQuery)
            ->selectRaw('ignore_reason, COUNT(*) as aggregate_count')
            ->groupBy('ignore_reason')
            ->orderByDesc('aggregate_count')
            ->pluck('aggregate_count', 'ignore_reason')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $predicateMatchByIgnoreReason = (clone $predicateQuery)
            ->selectRaw('ignore_reason, COUNT(*) as aggregate_count')
            ->groupBy('ignore_reason')
            ->orderByDesc('aggregate_count')
            ->pluck('aggregate_count', 'ignore_reason')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $candidatesByAgeBucket = $this->countCandidatesByAgeBucket($candidateQuery, $at);

        $estimatedPayloadBytes = (int) (clone $candidateQuery)
            ->selectRaw('COALESCE(SUM('.$this->payloadLengthExpression().'), 0) as aggregate_bytes')
            ->value('aggregate_bytes');

        $oldestCandidateReceivedAt = (clone $candidateQuery)->min('received_at');
        $newestCandidateReceivedAt = (clone $candidateQuery)->max('received_at');

        $sampleCandidateIds = (clone $candidateQuery)
            ->orderBy('id')
            ->limit($sampleLimit)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $manifestIdCount = 0;

        if ($manifestPath !== null && $manifestPath !== '') {
            $manifestIdCount = $this->writeManifest($candidateQuery, $manifestPath);
        }

        [$baselineCandidateCount, $baselineVariancePercent] = $this->baselineSettings();
        $baselineAnomaly = $baselineCandidateCount !== null
            && $baselineVariancePercent !== null
            && $candidateCount > $this->baselineUpperBound($baselineCandidateCount, $baselineVariancePercent);

        return new RetentionIgnoredEmailPruneSummary(
            inspectedAt: $at,
            dryRun: true,
            ignoredEmailDays: $days,
            receivedAtCutoff: $cutoff->toDateTimeString(),
            tableTotalCount: IncomingEmailMessage::query()->count(),
            predicateMatchCount: $predicateMatchCount,
            candidateCount: $candidateCount,
            candidatesByIgnoreReason: $candidatesByIgnoreReason,
            candidatesByAgeBucket: $candidatesByAgeBucket,
            predicateMatchByIgnoreReason: $predicateMatchByIgnoreReason,
            estimatedPayloadBytes: $estimatedPayloadBytes,
            oldestCandidateReceivedAt: $oldestCandidateReceivedAt !== null ? (string) $oldestCandidateReceivedAt : null,
            newestCandidateReceivedAt: $newestCandidateReceivedAt !== null ? (string) $newestCandidateReceivedAt : null,
            sampleCandidateIds: $sampleCandidateIds,
            manifestPath: $manifestPath !== null && $manifestPath !== '' ? $manifestPath : null,
            manifestIdCount: $manifestIdCount,
            candidatesWithIncidentId: $this->countPredicateRowsWithIncidentId($predicateQuery),
            candidatesWithOrderId: $this->countPredicateRowsWithOrderId($predicateQuery),
            candidatesWithLinkFk: $this->countPredicateRowsWithLinkFk($predicateQuery),
            candidatesWithOutgoingReplyFk: $this->countPredicateRowsWithOutgoingReplyFk($predicateQuery),
            candidatesWithPendingOutbox: $this->countPredicateRowsWithPendingOutbox($predicateQuery),
            candidatesWithoutProcessedAt: $this->countPredicateRowsWithoutProcessedAt($predicateQuery),
            excludedUnknownCustomerCount: $this->excludedUnknownCustomerCount($cutoff),
            excludedUnapprovedIgnoreReasonCount: max(0, $predicateMatchCount - $candidateCount),
            deletedCount: 0,
            batchesProcessed: 0,
            batchSize: max(1, (int) config('retention.ignored_email.prune_batch_size', 10000)),
            baselineAnomaly: $baselineAnomaly,
            baselineCandidateCount: $baselineCandidateCount,
            baselineVariancePercent: $baselineVariancePercent,
        );
    }

    public function ignoredEmailDays(): int
    {
        return max(1, (int) config('retention.ignored_email_days', 90));
    }

    public function receivedAtCutoff(?Carbon $at = null): Carbon
    {
        $at ??= now();

        return $at->copy()->subDays($this->ignoredEmailDays());
    }

    /**
     * Base safety predicate before ignore-reason allowlist filtering.
     *
     * @return Builder<IncomingEmailMessage>
     */
    public function predicateQuery(Carbon $cutoff): Builder
    {
        return IncomingEmailMessage::query()
            ->where('status', IncomingEmailMessageStatus::Ignored)
            ->whereNull('order_id')
            ->whereNull('incident_id')
            ->where('received_at', '<', $cutoff)
            ->whereNotNull('processed_at')
            ->whereNotExists(function ($subquery): void {
                $this->applyPendingInboundOutboxExists($subquery);
            })
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

    /**
     * Eligible delete candidates: base predicate plus approved ignore_reason allowlist.
     *
     * @return Builder<IncomingEmailMessage>
     */
    public function candidateQuery(Carbon $cutoff): Builder
    {
        $query = $this->predicateQuery($cutoff);
        $approvedReasons = $this->approvedIgnoreReasons();

        if ($approvedReasons === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn('ignore_reason', $approvedReasons);
    }

    /**
     * @return list<string>
     */
    public function approvedIgnoreReasons(): array
    {
        $reasons = config('retention.ignored_email.ignore_reasons', []);

        return array_values(array_filter(
            is_array($reasons) ? $reasons : [],
            static fn (mixed $reason): bool => is_string($reason) && $reason !== '',
        ));
    }

    /**
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<IncomingEmailMessage>  $subquery
     */
    public function applyPendingInboundOutboxExists($subquery): void
    {
        $subquery->select(DB::raw('1'))
            ->from('outbox_events as o')
            ->where('o.event_type', 'email.inbound.process')
            ->whereIn('o.status', [
                OutboxEventStatus::Pending->value,
                OutboxEventStatus::Processing->value,
            ])
            ->whereRaw(
                $this->incomingEmailMessageIdMatchesExpression('o.payload', 'incoming_email_messages.id'),
            );
    }

    public function incomingEmailMessageIdMatchesExpression(string $payloadColumn, string $messageIdColumn): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => sprintf(
                'CAST(json_extract(%s, \'$.incoming_email_message_id\') AS INTEGER) = %s',
                $payloadColumn,
                $messageIdColumn,
            ),
            default => sprintf(
                'JSON_UNQUOTE(JSON_EXTRACT(%s, \'$.incoming_email_message_id\')) = CAST(%s AS CHAR)',
                $payloadColumn,
                $messageIdColumn,
            ),
        };
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

    /**
     * @param  Builder<IncomingEmailMessage>  $candidateQuery
     * @return array<string, int>
     */
    private function countCandidatesByAgeBucket(Builder $candidateQuery, Carbon $at): array
    {
        $expression = match (DB::connection()->getDriverName()) {
            'sqlite' => sprintf(
                'CASE
                    WHEN received_at >= datetime("%s", "-7 day") THEN "0-7d"
                    WHEN received_at >= datetime("%s", "-30 day") THEN "8-30d"
                    WHEN received_at >= datetime("%s", "-90 day") THEN "31-90d"
                    WHEN received_at >= datetime("%s", "-180 day") THEN "91-180d"
                    WHEN received_at >= datetime("%s", "-365 day") THEN "181-365d"
                    ELSE ">365d"
                END',
                $at->toDateTimeString(),
                $at->toDateTimeString(),
                $at->toDateTimeString(),
                $at->toDateTimeString(),
                $at->toDateTimeString(),
            ),
            default => sprintf(
                'CASE
                    WHEN received_at >= "%s" - INTERVAL 7 DAY THEN "0-7d"
                    WHEN received_at >= "%s" - INTERVAL 30 DAY THEN "8-30d"
                    WHEN received_at >= "%s" - INTERVAL 90 DAY THEN "31-90d"
                    WHEN received_at >= "%s" - INTERVAL 180 DAY THEN "91-180d"
                    WHEN received_at >= "%s" - INTERVAL 365 DAY THEN "181-365d"
                    ELSE ">365d"
                END',
                $at->toDateTimeString(),
                $at->toDateTimeString(),
                $at->toDateTimeString(),
                $at->toDateTimeString(),
                $at->toDateTimeString(),
            ),
        };

        return (clone $candidateQuery)
            ->selectRaw($expression.' as age_bucket, COUNT(*) as aggregate_count')
            ->groupBy('age_bucket')
            ->when(
                DB::connection()->getDriverName() === 'sqlite',
                fn (Builder $query): Builder => $query->orderBy('age_bucket'),
                fn (Builder $query): Builder => $query->orderByRaw('FIELD(age_bucket, "0-7d", "8-30d", "31-90d", "91-180d", "181-365d", ">365d")'),
            )
            ->pluck('aggregate_count', 'age_bucket')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @param  Builder<IncomingEmailMessage>  $candidateQuery
     */
    private function writeManifest(Builder $candidateQuery, string $manifestPath): int
    {
        $directory = dirname($manifestPath);

        if ($directory !== '' && $directory !== '.' && ! is_dir($directory)) {
            throw new \RuntimeException(sprintf('Manifest directory does not exist: %s', $directory));
        }

        $handle = fopen($manifestPath, 'wb');

        if ($handle === false) {
            throw new \RuntimeException(sprintf('Unable to write manifest: %s', $manifestPath));
        }

        $count = 0;

        try {
            (clone $candidateQuery)
                ->orderBy('id')
                ->select('id')
                ->chunkById(5000, function ($rows) use ($handle, &$count): void {
                    foreach ($rows as $row) {
                        fwrite($handle, ((int) $row->id).PHP_EOL);
                        $count++;
                    }
                }, column: 'id');
        } finally {
            fclose($handle);
        }

        return $count;
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function baselineSettings(): array
    {
        $count = config('retention.ignored_email.baseline_candidate_count');
        $variance = config('retention.ignored_email.baseline_variance_percent', 20);

        return [
            is_numeric($count) ? (int) $count : null,
            is_numeric($variance) ? max(0, (int) $variance) : null,
        ];
    }

    private function baselineUpperBound(int $baselineCandidateCount, int $baselineVariancePercent): int
    {
        return (int) floor($baselineCandidateCount * (100 + $baselineVariancePercent) / 100);
    }

    /**
     * @param  Builder<IncomingEmailMessage>  $predicateQuery
     */
    private function countPredicateRowsWithIncidentId(Builder $predicateQuery): int
    {
        return (clone $predicateQuery)->whereNotNull('incident_id')->count();
    }

    /**
     * @param  Builder<IncomingEmailMessage>  $predicateQuery
     */
    private function countPredicateRowsWithOrderId(Builder $predicateQuery): int
    {
        return (clone $predicateQuery)->whereNotNull('order_id')->count();
    }

    /**
     * @param  Builder<IncomingEmailMessage>  $predicateQuery
     */
    private function countPredicateRowsWithLinkFk(Builder $predicateQuery): int
    {
        return (clone $predicateQuery)
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
     * @param  Builder<IncomingEmailMessage>  $predicateQuery
     */
    private function countPredicateRowsWithOutgoingReplyFk(Builder $predicateQuery): int
    {
        return (clone $predicateQuery)
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
     * @param  Builder<IncomingEmailMessage>  $predicateQuery
     */
    private function countPredicateRowsWithPendingOutbox(Builder $predicateQuery): int
    {
        return (clone $predicateQuery)
            ->whereExists(function ($subquery): void {
                $this->applyPendingInboundOutboxExists($subquery);
            })
            ->count();
    }

    /**
     * @param  Builder<IncomingEmailMessage>  $predicateQuery
     */
    private function countPredicateRowsWithoutProcessedAt(Builder $predicateQuery): int
    {
        return IncomingEmailMessage::query()
            ->where('status', IncomingEmailMessageStatus::Ignored)
            ->whereNull('order_id')
            ->whereNull('incident_id')
            ->where('received_at', '<', $this->receivedAtCutoff())
            ->whereNull('processed_at')
            ->count();
    }

    private function excludedUnknownCustomerCount(Carbon $cutoff): int
    {
        if (in_array('unknown_customer', $this->approvedIgnoreReasons(), true)) {
            return 0;
        }

        return (clone $this->predicateQuery($cutoff))
            ->where('ignore_reason', 'unknown_customer')
            ->count();
    }

    private function emptySummary(
        Carbon $at,
        bool $dryRun,
        int $days,
        Carbon $cutoff,
        ?string $manifestPath,
    ): RetentionIgnoredEmailPruneSummary {
        [$baselineCandidateCount, $baselineVariancePercent] = $this->baselineSettings();

        return new RetentionIgnoredEmailPruneSummary(
            inspectedAt: $at,
            dryRun: $dryRun,
            ignoredEmailDays: $days,
            receivedAtCutoff: $cutoff->toDateTimeString(),
            tableTotalCount: 0,
            predicateMatchCount: 0,
            candidateCount: 0,
            candidatesByIgnoreReason: [],
            candidatesByAgeBucket: [],
            predicateMatchByIgnoreReason: [],
            estimatedPayloadBytes: 0,
            oldestCandidateReceivedAt: null,
            newestCandidateReceivedAt: null,
            sampleCandidateIds: [],
            manifestPath: $manifestPath !== null && $manifestPath !== '' ? $manifestPath : null,
            manifestIdCount: 0,
            candidatesWithIncidentId: 0,
            candidatesWithOrderId: 0,
            candidatesWithLinkFk: 0,
            candidatesWithOutgoingReplyFk: 0,
            candidatesWithPendingOutbox: 0,
            candidatesWithoutProcessedAt: 0,
            excludedUnknownCustomerCount: 0,
            excludedUnapprovedIgnoreReasonCount: 0,
            deletedCount: 0,
            batchesProcessed: 0,
            batchSize: max(1, (int) config('retention.ignored_email.prune_batch_size', 10000)),
            baselineAnomaly: false,
            baselineCandidateCount: $baselineCandidateCount,
            baselineVariancePercent: $baselineVariancePercent,
        );
    }
}
