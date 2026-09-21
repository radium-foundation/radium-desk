<?php

namespace App\Services\Retention;

use App\Data\Retention\RetentionUnknownCustomerPruneSummary;
use App\Enums\IncomingEmailMessageStatus;
use App\Models\IncomingEmailMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RetentionUnknownCustomerInspectionService
{
    private const IGNORE_REASON = 'unknown_customer';

    /** @var list<IncomingEmailMessageStatus> */
    private const FORBIDDEN_STATUSES = [
        IncomingEmailMessageStatus::Received,
        IncomingEmailMessageStatus::Processing,
        IncomingEmailMessageStatus::Failed,
        IncomingEmailMessageStatus::Linked,
        IncomingEmailMessageStatus::HistoricalCustomer,
        IncomingEmailMessageStatus::NeedsReview,
    ];

    public function __construct(
        private readonly RetentionIgnoredEmailInspectionService $ignoredEmailInspectionService,
    ) {}

    /**
     * Read-only inspection for rolling unknown_customer ignored-email retention candidates.
     *
     * Uses received_at (not created_at) for the age cutoff.
     */
    public function inspect(?Carbon $at = null, ?string $manifestPath = null): RetentionUnknownCustomerPruneSummary
    {
        $at ??= now();
        $days = $this->unknownCustomerDays();
        $cutoff = $this->receivedAtCutoff($at);
        $sampleLimit = max(1, (int) config('retention.unknown_customer.sample_id_limit', 10));

        if (! Schema::hasTable('incoming_email_messages')) {
            return $this->emptySummary($at, $days, $cutoff, $manifestPath);
        }

        $candidateQuery = $this->candidateQuery($cutoff);
        $candidateCount = (clone $candidateQuery)->count();

        $candidatesByAgeBucket = $this->countCandidatesByAgeBucket($candidateQuery, $at);

        $estimatedPayloadBytes = (int) (clone $candidateQuery)
            ->selectRaw('COALESCE(SUM('.$this->payloadLengthExpression().'), 0) as aggregate_bytes')
            ->value('aggregate_bytes');

        $oldestCandidateReceivedAt = (clone $candidateQuery)->min('received_at');
        $newestCandidateReceivedAt = (clone $candidateQuery)->max('received_at');
        $oldestCandidateId = (clone $candidateQuery)->min('id');
        $newestCandidateId = (clone $candidateQuery)->max('id');

        $sampleCandidateIds = (clone $candidateQuery)
            ->orderBy('id')
            ->limit($sampleLimit)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $manifestIdCount = 0;
        $manifestSha256 = null;

        if ($manifestPath !== null && $manifestPath !== '') {
            $manifestIdCount = $this->writeManifest($candidateQuery, $manifestPath);
            $manifestSha256 = $this->hashManifest($manifestPath);
        }

        [$baselineCandidateCount, $baselineVariancePercent] = $this->baselineSettings();
        $baselineAnomaly = $baselineCandidateCount !== null
            && $baselineVariancePercent !== null
            && $candidateCount > $this->baselineUpperBound($baselineCandidateCount, $baselineVariancePercent);

        return new RetentionUnknownCustomerPruneSummary(
            inspectedAt: $at,
            dryRun: true,
            unknownCustomerDays: $days,
            receivedAtCutoff: $cutoff->toDateTimeString(),
            tableTotalCount: IncomingEmailMessage::query()->count(),
            unknownCustomerIgnoredTotal: $this->unknownCustomerIgnoredTotal(),
            candidateCount: $candidateCount,
            candidatesByAgeBucket: $candidatesByAgeBucket,
            estimatedPayloadBytes: $estimatedPayloadBytes,
            oldestCandidateReceivedAt: $oldestCandidateReceivedAt !== null ? (string) $oldestCandidateReceivedAt : null,
            newestCandidateReceivedAt: $newestCandidateReceivedAt !== null ? (string) $newestCandidateReceivedAt : null,
            oldestCandidateId: $oldestCandidateId !== null ? (int) $oldestCandidateId : null,
            newestCandidateId: $newestCandidateId !== null ? (int) $newestCandidateId : null,
            sampleCandidateIds: $sampleCandidateIds,
            manifestPath: $manifestPath !== null && $manifestPath !== '' ? $manifestPath : null,
            manifestIdCount: $manifestIdCount,
            manifestSha256: $manifestSha256,
            candidatesWithIncidentId: $this->countPredicateRowsWithIncidentId($cutoff),
            candidatesWithOrderId: $this->countPredicateRowsWithOrderId($cutoff),
            candidatesWithLinkFk: $this->countPredicateRowsWithLinkFk($cutoff),
            candidatesWithOutgoingReplyFk: $this->countPredicateRowsWithOutgoingReplyFk($cutoff),
            candidatesWithPendingOutbox: $this->countPredicateRowsWithPendingOutbox($cutoff),
            candidatesWithoutProcessedAt: $this->countRowsWithoutProcessedAt($cutoff),
            candidatesWithWrongStatus: $this->countRowsWithWrongStatus($cutoff),
            candidatesWithWrongIgnoreReason: $this->countRowsWithWrongIgnoreReason($cutoff),
            deletedCount: 0,
            batchesProcessed: 0,
            batchSize: max(1, (int) config('retention.unknown_customer.prune_batch_size', 10000)),
            baselineAnomaly: $baselineAnomaly,
            baselineCandidateCount: $baselineCandidateCount,
            baselineVariancePercent: $baselineVariancePercent,
        );
    }

    public function unknownCustomerDays(): int
    {
        return max(1, (int) config('retention.unknown_customer_days', 30));
    }

    public function receivedAtCutoff(?Carbon $at = null): Carbon
    {
        $at ??= now();

        return $at->copy()->subDays($this->unknownCustomerDays());
    }

    /**
     * Eligible delete candidates for ignored unknown_customer email rows.
     *
     * @return Builder<IncomingEmailMessage>
     */
    public function candidateQuery(Carbon $cutoff): Builder
    {
        return IncomingEmailMessage::query()
            ->where('status', IncomingEmailMessageStatus::Ignored)
            ->where('ignore_reason', self::IGNORE_REASON)
            ->whereNull('order_id')
            ->whereNull('incident_id')
            ->where('received_at', '<', $cutoff)
            ->whereNotNull('processed_at')
            ->whereNotExists(function ($subquery): void {
                $this->ignoredEmailInspectionService->applyPendingInboundOutboxExists($subquery);
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
     * @return Builder<IncomingEmailMessage>
     */
    private function basePopulationQuery(): Builder
    {
        return IncomingEmailMessage::query()
            ->where('status', IncomingEmailMessageStatus::Ignored)
            ->where('ignore_reason', self::IGNORE_REASON);
    }

    private function unknownCustomerIgnoredTotal(): int
    {
        return (clone $this->basePopulationQuery())->count();
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
                    WHEN received_at >= datetime("%s", "-30 day") THEN "0-30d"
                    WHEN received_at >= datetime("%s", "-90 day") THEN "31-90d"
                    WHEN received_at >= datetime("%s", "-180 day") THEN "91-180d"
                    WHEN received_at >= datetime("%s", "-365 day") THEN "181-365d"
                    ELSE ">365d"
                END',
                $at->toDateTimeString(),
                $at->toDateTimeString(),
                $at->toDateTimeString(),
                $at->toDateTimeString(),
            ),
            default => sprintf(
                'CASE
                    WHEN received_at >= "%s" - INTERVAL 30 DAY THEN "0-30d"
                    WHEN received_at >= "%s" - INTERVAL 90 DAY THEN "31-90d"
                    WHEN received_at >= "%s" - INTERVAL 180 DAY THEN "91-180d"
                    WHEN received_at >= "%s" - INTERVAL 365 DAY THEN "181-365d"
                    ELSE ">365d"
                END',
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
                fn (Builder $query): Builder => $query->orderByRaw('FIELD(age_bucket, "0-30d", "31-90d", "91-180d", "181-365d", ">365d")'),
            )
            ->pluck('aggregate_count', 'age_bucket')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
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
     */
    private function writeManifest(Builder $candidateQuery, string $manifestPath): int
    {
        $directory = dirname($manifestPath);

        if ($directory !== '' && $directory !== '.' && ! is_dir($directory)) {
            if (! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw new \RuntimeException(sprintf('Unable to create manifest directory: %s', $directory));
            }
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

    private function hashManifest(string $manifestPath): ?string
    {
        if (! is_file($manifestPath)) {
            return null;
        }

        $hash = hash_file('sha256', $manifestPath);

        return is_string($hash) ? $hash : null;
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function baselineSettings(): array
    {
        $count = config('retention.unknown_customer.baseline_candidate_count');
        $variance = config('retention.unknown_customer.baseline_variance_percent', 20);

        return [
            is_numeric($count) ? (int) $count : null,
            is_numeric($variance) ? max(0, (int) $variance) : null,
        ];
    }

    private function baselineUpperBound(int $baselineCandidateCount, int $baselineVariancePercent): int
    {
        return (int) floor($baselineCandidateCount * (100 + $baselineVariancePercent) / 100);
    }

    private function countPredicateRowsWithIncidentId(Carbon $cutoff): int
    {
        return (clone $this->candidateQuery($cutoff))->whereNotNull('incident_id')->count();
    }

    private function countPredicateRowsWithOrderId(Carbon $cutoff): int
    {
        return (clone $this->candidateQuery($cutoff))->whereNotNull('order_id')->count();
    }

    private function countPredicateRowsWithLinkFk(Carbon $cutoff): int
    {
        return (clone $this->candidateQuery($cutoff))
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

    private function countPredicateRowsWithOutgoingReplyFk(Carbon $cutoff): int
    {
        return (clone $this->candidateQuery($cutoff))
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

    private function countPredicateRowsWithPendingOutbox(Carbon $cutoff): int
    {
        return (clone $this->candidateQuery($cutoff))
            ->whereExists(function ($subquery): void {
                $this->ignoredEmailInspectionService->applyPendingInboundOutboxExists($subquery);
            })
            ->count();
    }

    private function countRowsWithoutProcessedAt(Carbon $cutoff): int
    {
        return (clone $this->basePopulationQuery())
            ->whereNull('order_id')
            ->whereNull('incident_id')
            ->where('received_at', '<', $cutoff)
            ->whereNull('processed_at')
            ->count();
    }

    private function countRowsWithWrongStatus(Carbon $cutoff): int
    {
        return IncomingEmailMessage::query()
            ->where('ignore_reason', self::IGNORE_REASON)
            ->whereIn('status', self::FORBIDDEN_STATUSES)
            ->whereNull('order_id')
            ->whereNull('incident_id')
            ->where('received_at', '<', $cutoff)
            ->whereNotNull('processed_at')
            ->count();
    }

    private function countRowsWithWrongIgnoreReason(Carbon $cutoff): int
    {
        return IncomingEmailMessage::query()
            ->where('status', IncomingEmailMessageStatus::Ignored)
            ->where('ignore_reason', '!=', self::IGNORE_REASON)
            ->whereNull('order_id')
            ->whereNull('incident_id')
            ->where('received_at', '<', $cutoff)
            ->whereNotNull('processed_at')
            ->count();
    }

    private function emptySummary(
        Carbon $at,
        int $days,
        Carbon $cutoff,
        ?string $manifestPath,
    ): RetentionUnknownCustomerPruneSummary {
        [$baselineCandidateCount, $baselineVariancePercent] = $this->baselineSettings();

        return new RetentionUnknownCustomerPruneSummary(
            inspectedAt: $at,
            dryRun: true,
            unknownCustomerDays: $days,
            receivedAtCutoff: $cutoff->toDateTimeString(),
            tableTotalCount: 0,
            unknownCustomerIgnoredTotal: 0,
            candidateCount: 0,
            candidatesByAgeBucket: [],
            estimatedPayloadBytes: 0,
            oldestCandidateReceivedAt: null,
            newestCandidateReceivedAt: null,
            oldestCandidateId: null,
            newestCandidateId: null,
            sampleCandidateIds: [],
            manifestPath: $manifestPath !== null && $manifestPath !== '' ? $manifestPath : null,
            manifestIdCount: 0,
            manifestSha256: null,
            candidatesWithIncidentId: 0,
            candidatesWithOrderId: 0,
            candidatesWithLinkFk: 0,
            candidatesWithOutgoingReplyFk: 0,
            candidatesWithPendingOutbox: 0,
            candidatesWithoutProcessedAt: 0,
            candidatesWithWrongStatus: 0,
            candidatesWithWrongIgnoreReason: 0,
            deletedCount: 0,
            batchesProcessed: 0,
            batchSize: max(1, (int) config('retention.unknown_customer.prune_batch_size', 10000)),
            baselineAnomaly: false,
            baselineCandidateCount: $baselineCandidateCount,
            baselineVariancePercent: $baselineVariancePercent,
        );
    }
}
