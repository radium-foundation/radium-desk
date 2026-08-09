<?php

namespace App\Services\Cashfree;

use App\Data\CashfreeFailedWebhookClassificationReport;
use App\Data\CashfreeFailedWebhookRecord;
use App\Data\CashfreeMissingPaidOrderRecord;
use App\Data\CashfreePaymentReconciliationReport;
use App\Data\CashfreePaymentReconciliationScalars;
use App\Enums\CashfreeHistoricalRecoveryDisposition;
use App\Enums\CashfreeWebhookFailureCategory;
use App\Models\CashfreeWebhookLog;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CashfreePaymentIntegrityService
{
    private const LOOKUP_CHUNK_SIZE = 500;

    /**
     * Minimum columns for successfulPaymentLogsByCfPaymentId().
     * Integrity classification only needs payload + identity + ordering fields.
     *
     * @var list<string>
     */
    private const SUCCESSFUL_PAYMENT_HYDRATE_COLUMNS = [
        'id',
        'cf_payment_id',
        'request_payload',
        'received_at',
    ];

    public function __construct(
        private readonly CashfreeWebhookPayloadParser $payloadParser,
    ) {}

    public function reconcile(): CashfreePaymentReconciliationReport
    {
        $successfulPayments = $this->successfulPaymentLogsByCfPaymentId();
        $missingOrders = $this->missingPaidOrders($successfulPayments);

        return new CashfreePaymentReconciliationReport(
            successfulCashfreePayments: $successfulPayments->count(),
            deskOrders: $this->deskOrderCount(),
            missingOrdersCount: $missingOrders->count(),
            failedProcessing: $this->failedSuccessfulPaymentProcessingCount(),
            paidWithoutDeskOrderCount: $missingOrders->count(),
            missingOrders: $missingOrders
                ->map(fn (array $entry): CashfreeMissingPaidOrderRecord => $this->toMissingRecord($entry))
                ->values()
                ->all(),
        );
    }

    /**
     * Scalar reconciliation KPIs for health summaries (evening report, dashboards).
     *
     * Avoids full-universe missingPaidOrders assessment. Missing count uses the
     * candidate paid-without path (equivalent to reconcile missingOrdersCount).
     * Successful-payment cardinality still uses the historical success map.
     */
    public function reconciliationScalars(): CashfreePaymentReconciliationScalars
    {
        return new CashfreePaymentReconciliationScalars(
            successfulCashfreePayments: $this->successfulPaymentLogsByCfPaymentId()->count(),
            deskOrders: $this->deskOrderCount(),
            missingOrdersCount: $this->paidWithoutDeskOrderCount(),
            failedProcessing: $this->failedSuccessfulPaymentProcessingCount(),
        );
    }

    public function failedSuccessfulPaymentProcessingCount(): int
    {
        return CashfreeWebhookLog::query()
            ->where('processing_status', CashfreeWebhookLog::STATUS_FAILED)
            ->get()
            ->filter(fn (CashfreeWebhookLog $log): bool => $this->payloadParser->isSuccessfulPayment($log->request_payload ?? []))
            ->count();
    }

    public function paidWithoutDeskOrderCount(): int
    {
        return $this->candidatePaidWithoutDeskOrders()->count();
    }

    /**
     * Single-pass missing paid-order count + sample IDs (avoids reconcile() double scan).
     *
     * Uses the anti-join candidate universe (same as paidWithoutDeskOrderCount).
     * Full historical completeness remains on reconcile() / successfulPaymentLogsByCfPaymentId().
     *
     * @return array{count: int, order_ids: list<string>}
     */
    public function missingPaidOrderSample(int $limit = 5): array
    {
        $missingOrders = $this->candidatePaidWithoutDeskOrders();

        $orderIds = $missingOrders
            ->take(max(0, $limit))
            ->map(function (array $entry): string {
                $record = $this->toMissingRecord($entry);

                return (string) ($record->orderId ?? $record->cfPaymentId ?? '');
            })
            ->filter()
            ->values()
            ->all();

        return [
            'count' => $missingOrders->count(),
            'order_ids' => $orderIds,
        ];
    }

    /**
     * Assessed paid-without rows from the anti-join candidate universe.
     *
     * Candidates are webhook rows whose cf_payment_id is not present on
     * orders.cashfree_payment_id, plus null-column rows (payload may still
     * carry a usable payment id). Anti-join count alone is never the answer —
     * each candidate still runs through assessLog / AlreadyExists semantics.
     *
     * @return Collection<int, array{log: CashfreeWebhookLog, disposition: CashfreeHistoricalRecoveryDisposition, reason: string}>
     */
    public function candidatePaidWithoutDeskOrders(): Collection
    {
        return $this->missingPaidOrders($this->candidateSuccessfulPaymentLogsByCfPaymentId());
    }

    /**
     * Earliest successful payment log per cf_payment_id within the candidate universe.
     *
     * @return Collection<string, CashfreeWebhookLog>
     */
    public function candidateSuccessfulPaymentLogsByCfPaymentId(): Collection
    {
        /** @var Collection<string, CashfreeWebhookLog> $byPaymentId */
        $byPaymentId = collect();

        foreach ($this->paidWithoutCandidateWebhookLogs() as $log) {
            if (! $this->payloadParser->isSuccessfulPayment($log->request_payload ?? [])) {
                continue;
            }

            $cfPaymentId = $this->resolveCfPaymentId($log);

            if ($cfPaymentId === null) {
                continue;
            }

            if (! $byPaymentId->has($cfPaymentId)) {
                $byPaymentId->put($cfPaymentId, $log);
            }
        }

        return $byPaymentId;
    }

    public function activeFailedWebhookCount(): int
    {
        return $this->classifyFailedWebhooks()->activeFailedWebhooks;
    }

    public function historicalResolvedFailureCount(): int
    {
        return $this->classifyFailedWebhooks()->historicalResolvedFailures;
    }

    public function classifyFailedWebhooks(): CashfreeFailedWebhookClassificationReport
    {
        $logs = CashfreeWebhookLog::query()
            ->where('processing_status', CashfreeWebhookLog::STATUS_FAILED)
            ->orderBy('processed_at')
            ->orderBy('id')
            ->get();

        $index = $this->buildAssessmentIndex($logs);

        $records = $logs
            ->map(fn (CashfreeWebhookLog $log): CashfreeFailedWebhookRecord => $this->classifyFailedLog($log, $index))
            ->values();

        $countsByCategory = [];

        foreach (CashfreeWebhookFailureCategory::cases() as $category) {
            $countsByCategory[$category->value] = 0;
        }

        foreach ($records as $record) {
            $countsByCategory[$record->category->value]++;
        }

        $affectedOrderIds = $records
            ->map(fn (CashfreeFailedWebhookRecord $record): ?string => $record->orderId)
            ->filter(fn (?string $orderId): bool => filled($orderId))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $failedAtTimestamps = $records
            ->map(fn (CashfreeFailedWebhookRecord $record): Carbon => $record->failedAt);

        return new CashfreeFailedWebhookClassificationReport(
            totalFailed: $records->count(),
            activeFailedWebhooks: $countsByCategory[CashfreeWebhookFailureCategory::Unresolved->value],
            historicalResolvedFailures: $countsByCategory[CashfreeWebhookFailureCategory::DuplicateSucceeded->value]
                + $countsByCategory[CashfreeWebhookFailureCategory::PaymentExistsInDesk->value],
            invalidEventFailures: $countsByCategory[CashfreeWebhookFailureCategory::InvalidEvent->value],
            countsByCategory: $countsByCategory,
            oldestFailedAt: $failedAtTimestamps->first(),
            newestFailedAt: $failedAtTimestamps->last(),
            affectedOrderIds: $affectedOrderIds,
            records: $records->all(),
        );
    }

    /**
     * @param  array{
     *     payment_ids: array<string, true>,
     *     order_ids: array<string, true>,
     *     processed_payment_log_ids: array<string, list<int>>
     * }|null  $index
     */
    public function classifyFailedLog(CashfreeWebhookLog $log, ?array $index = null): CashfreeFailedWebhookRecord
    {
        $payload = $log->request_payload ?? [];

        if (! $this->payloadParser->isSuccessfulPayment($payload)) {
            return $this->failedWebhookRecord(
                $log,
                CashfreeWebhookFailureCategory::InvalidEvent,
                'payment_not_success',
            );
        }

        $assessment = $this->assessLog($log, $index);

        $category = match ($assessment['reason']) {
            'cashfree_payment_id_exists', 'order_id_exists' => CashfreeWebhookFailureCategory::PaymentExistsInDesk,
            'processed_webhook_exists' => CashfreeWebhookFailureCategory::DuplicateSucceeded,
            'payment_not_success' => CashfreeWebhookFailureCategory::InvalidEvent,
            default => CashfreeWebhookFailureCategory::Unresolved,
        };

        return $this->failedWebhookRecord($log, $category, $assessment['reason']);
    }

    /**
     * Alert semantics when paid + failed classification counts are already loaded.
     * Equivalent to requiresCashfreeHealthAlert() without re-running hydrate/classify.
     */
    public function requiresCashfreeHealthAlertFromCounts(
        int $paidWithoutDeskOrderCount,
        int $activeFailedWebhooks,
    ): bool {
        return $paidWithoutDeskOrderCount > 0 || $activeFailedWebhooks > 0;
    }

    public function requiresCashfreeHealthAlert(): bool
    {
        return $this->requiresCashfreeHealthAlertFromCounts(
            $this->paidWithoutDeskOrderCount(),
            $this->activeFailedWebhookCount(),
        );
    }

    /**
     * @param  array{
     *     payment_ids: array<string, true>,
     *     order_ids: array<string, true>,
     *     processed_payment_log_ids: array<string, list<int>>
     * }|null  $index
     * @return array{log: CashfreeWebhookLog, disposition: CashfreeHistoricalRecoveryDisposition, reason: string}
     */
    public function assessLog(CashfreeWebhookLog $log, ?array $index = null): array
    {
        $payload = $log->request_payload ?? [];

        if (! $this->payloadParser->isSuccessfulPayment($payload)) {
            return $this->assessment($log, CashfreeHistoricalRecoveryDisposition::Unsafe, 'payment_not_success');
        }

        $cfPaymentId = $this->resolveCfPaymentId($log);

        if ($cfPaymentId === null) {
            return $this->assessment($log, CashfreeHistoricalRecoveryDisposition::Unsafe, 'missing_cf_payment_id');
        }

        if ($this->paymentIdExists($cfPaymentId, $index)) {
            return $this->assessment($log, CashfreeHistoricalRecoveryDisposition::AlreadyExists, 'cashfree_payment_id_exists');
        }

        $businessOrderId = $this->payloadParser->orderId($payload);

        if ($businessOrderId !== null && $this->businessOrderIdExists($businessOrderId, $index)) {
            return $this->assessment($log, CashfreeHistoricalRecoveryDisposition::AlreadyExists, 'order_id_exists');
        }

        if ($this->processedSiblingExists($cfPaymentId, $log->id, $index)) {
            return $this->assessment($log, CashfreeHistoricalRecoveryDisposition::AlreadyExists, 'processed_webhook_exists');
        }

        if ($businessOrderId === null) {
            return $this->assessment($log, CashfreeHistoricalRecoveryDisposition::Unsafe, 'missing_order_id');
        }

        return $this->assessment($log, CashfreeHistoricalRecoveryDisposition::Recoverable, 'ready');
    }

    /**
     * Full historical successful-payment map (entire webhook log table).
     * Required for reconcile() totals / CLI completeness. Prefer
     * candidateSuccessfulPaymentLogsByCfPaymentId() for paid-without / sample.
     *
     * @return Collection<string, CashfreeWebhookLog>
     */
    public function successfulPaymentLogsByCfPaymentId(): Collection
    {
        /** @var Collection<string, CashfreeWebhookLog> $byPaymentId */
        $byPaymentId = collect();

        CashfreeWebhookLog::query()
            ->select(self::SUCCESSFUL_PAYMENT_HYDRATE_COLUMNS)
            ->orderBy('received_at')
            ->orderBy('id')
            ->get()
            ->each(function (CashfreeWebhookLog $log) use ($byPaymentId): void {
                if (! $this->payloadParser->isSuccessfulPayment($log->request_payload ?? [])) {
                    return;
                }

                $cfPaymentId = $this->resolveCfPaymentId($log);

                if ($cfPaymentId === null) {
                    return;
                }

                if (! $byPaymentId->has($cfPaymentId)) {
                    $byPaymentId->put($cfPaymentId, $log);
                }
            });

        return $byPaymentId;
    }

    /**
     * Anti-join + null-column candidate rows for paid-without discovery.
     * Ordered earliest-first so earliest-success-per-cf_payment_id is preserved.
     *
     * @return Collection<int, CashfreeWebhookLog>
     */
    private function paidWithoutCandidateWebhookLogs(): Collection
    {
        $antiJoinIds = CashfreeWebhookLog::query()
            ->whereNotNull('cf_payment_id')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('orders')
                    ->whereColumn('orders.cashfree_payment_id', 'cashfree_webhook_logs.cf_payment_id');
            })
            ->pluck('id');

        $nullColumnIds = CashfreeWebhookLog::query()
            ->whereNull('cf_payment_id')
            ->pluck('id');

        $candidateIds = $antiJoinIds
            ->concat($nullColumnIds)
            ->unique()
            ->values()
            ->all();

        if ($candidateIds === []) {
            return collect();
        }

        return CashfreeWebhookLog::query()
            ->select(self::SUCCESSFUL_PAYMENT_HYDRATE_COLUMNS)
            ->whereIn('id', $candidateIds)
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<string, CashfreeWebhookLog>  $successfulPayments
     * @return Collection<int, array{log: CashfreeWebhookLog, disposition: CashfreeHistoricalRecoveryDisposition, reason: string}>
     */
    private function missingPaidOrders(Collection $successfulPayments): Collection
    {
        $index = $this->buildAssessmentIndex($successfulPayments->values());

        return $successfulPayments
            ->map(fn (CashfreeWebhookLog $log): array => $this->assessLog($log, $index))
            ->filter(function (array $entry): bool {
                return ! in_array($entry['disposition'], [
                    CashfreeHistoricalRecoveryDisposition::AlreadyExists,
                ], true);
            })
            ->values();
    }

    /**
     * @param  Collection<int, CashfreeWebhookLog>  $logs
     * @return array{
     *     payment_ids: array<string, true>,
     *     order_ids: array<string, true>,
     *     processed_payment_log_ids: array<string, list<int>>
     * }
     */
    private function buildAssessmentIndex(Collection $logs): array
    {
        $paymentIds = [];
        $orderIds = [];

        foreach ($logs as $log) {
            $payload = $log->request_payload ?? [];

            if (! $this->payloadParser->isSuccessfulPayment($payload)) {
                continue;
            }

            $cfPaymentId = $this->resolveCfPaymentId($log);

            if ($cfPaymentId !== null) {
                $paymentIds[$cfPaymentId] = true;
            }

            $businessOrderId = $this->payloadParser->orderId($payload);

            if ($businessOrderId !== null) {
                $orderIds[$businessOrderId] = true;
            }
        }

        return [
            'payment_ids' => $this->existingCashfreePaymentIds(array_keys($paymentIds)),
            'order_ids' => $this->existingBusinessOrderIds(array_keys($orderIds)),
            'processed_payment_log_ids' => $this->processedCashfreePaymentLogIds(array_keys($paymentIds)),
        ];
    }

    /**
     * @param  list<string>  $paymentIds
     * @return array<string, true>
     */
    private function existingCashfreePaymentIds(array $paymentIds): array
    {
        $existing = [];

        foreach (array_chunk($paymentIds, self::LOOKUP_CHUNK_SIZE) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            Order::query()
                ->whereIn('cashfree_payment_id', $chunk)
                ->pluck('cashfree_payment_id')
                ->each(function (mixed $id) use (&$existing): void {
                    $key = trim((string) $id);

                    if ($key !== '') {
                        $existing[$key] = true;
                    }
                });
        }

        return $existing;
    }

    /**
     * @param  list<string>  $orderIds
     * @return array<string, true>
     */
    private function existingBusinessOrderIds(array $orderIds): array
    {
        $existing = [];

        foreach (array_chunk($orderIds, self::LOOKUP_CHUNK_SIZE) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            Order::query()
                ->whereIn('order_id', $chunk)
                ->pluck('order_id')
                ->each(function (mixed $id) use (&$existing): void {
                    $key = trim((string) $id);

                    if ($key !== '') {
                        $existing[$key] = true;
                    }
                });
        }

        return $existing;
    }

    /**
     * @param  list<string>  $paymentIds
     * @return array<string, list<int>>
     */
    private function processedCashfreePaymentLogIds(array $paymentIds): array
    {
        $processed = [];

        foreach (array_chunk($paymentIds, self::LOOKUP_CHUNK_SIZE) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            CashfreeWebhookLog::query()
                ->whereIn('cf_payment_id', $chunk)
                ->where('processing_status', CashfreeWebhookProcessorService::STATUS_PROCESSED)
                ->whereNotNull('incident_id')
                ->get(['id', 'cf_payment_id'])
                ->each(function (CashfreeWebhookLog $row) use (&$processed): void {
                    $key = trim((string) $row->cf_payment_id);

                    if ($key === '') {
                        return;
                    }

                    $processed[$key][] = (int) $row->id;
                });
        }

        return $processed;
    }

    /**
     * @param  array{
     *     payment_ids: array<string, true>,
     *     order_ids: array<string, true>,
     *     processed_payment_log_ids: array<string, list<int>>
     * }|null  $index
     */
    private function paymentIdExists(string $cfPaymentId, ?array $index): bool
    {
        if ($index !== null) {
            return isset($index['payment_ids'][$cfPaymentId]);
        }

        return Order::query()->where('cashfree_payment_id', $cfPaymentId)->exists();
    }

    /**
     * @param  array{
     *     payment_ids: array<string, true>,
     *     order_ids: array<string, true>,
     *     processed_payment_log_ids: array<string, list<int>>
     * }|null  $index
     */
    private function businessOrderIdExists(string $businessOrderId, ?array $index): bool
    {
        if ($index !== null) {
            return isset($index['order_ids'][$businessOrderId]);
        }

        return Order::query()->where('order_id', $businessOrderId)->exists();
    }

    /**
     * @param  array{
     *     payment_ids: array<string, true>,
     *     order_ids: array<string, true>,
     *     processed_payment_log_ids: array<string, list<int>>
     * }|null  $index
     */
    private function processedSiblingExists(string $cfPaymentId, int $logId, ?array $index): bool
    {
        if ($index !== null) {
            foreach ($index['processed_payment_log_ids'][$cfPaymentId] ?? [] as $processedLogId) {
                if ((int) $processedLogId !== $logId) {
                    return true;
                }
            }

            return false;
        }

        return CashfreeWebhookLog::query()
            ->where('cf_payment_id', $cfPaymentId)
            ->where('id', '!=', $logId)
            ->where('processing_status', CashfreeWebhookProcessorService::STATUS_PROCESSED)
            ->whereNotNull('incident_id')
            ->exists();
    }

    /**
     * @param  array{log: CashfreeWebhookLog, disposition: CashfreeHistoricalRecoveryDisposition, reason: string}  $entry
     */
    private function toMissingRecord(array $entry): CashfreeMissingPaidOrderRecord
    {
        $log = $entry['log'];
        $payload = $log->request_payload ?? [];
        $paymentDate = $this->payloadParser->paymentDate($payload);

        return new CashfreeMissingPaidOrderRecord(
            webhookLogId: $log->id,
            orderId: $this->payloadParser->orderId($payload),
            cfPaymentId: (string) $this->resolveCfPaymentId($log),
            paidAt: $paymentDate !== null ? Carbon::parse($paymentDate) : $log->received_at,
            recoveryEligibility: $entry['disposition'],
            recoveryReason: $entry['reason'],
        );
    }

    /**
     * @return array{log: CashfreeWebhookLog, disposition: CashfreeHistoricalRecoveryDisposition, reason: string}
     */
    private function assessment(
        CashfreeWebhookLog $log,
        CashfreeHistoricalRecoveryDisposition $disposition,
        string $reason,
    ): array {
        return [
            'log' => $log,
            'disposition' => $disposition,
            'reason' => $reason,
        ];
    }

    private function deskOrderCount(): int
    {
        return Order::query()->whereNotNull('cashfree_payment_id')->count();
    }

    private function resolveCfPaymentId(CashfreeWebhookLog $log): ?string
    {
        $payload = $log->request_payload ?? [];

        return $this->payloadParser->cfPaymentId($payload) ?? $log->cf_payment_id;
    }

    private function failedWebhookRecord(
        CashfreeWebhookLog $log,
        CashfreeWebhookFailureCategory $category,
        string $reason,
    ): CashfreeFailedWebhookRecord {
        $payload = $log->request_payload ?? [];

        return new CashfreeFailedWebhookRecord(
            webhookLogId: $log->id,
            category: $category,
            reason: $reason,
            orderId: $this->payloadParser->orderId($payload),
            cfPaymentId: $this->resolveCfPaymentId($log),
            failedAt: $log->processed_at ?? $log->received_at ?? now(),
        );
    }
}
