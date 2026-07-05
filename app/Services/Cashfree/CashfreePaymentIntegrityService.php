<?php

namespace App\Services\Cashfree;

use App\Data\CashfreeFailedWebhookClassificationReport;
use App\Data\CashfreeFailedWebhookRecord;
use App\Data\CashfreeMissingPaidOrderRecord;
use App\Data\CashfreePaymentReconciliationReport;
use App\Enums\CashfreeHistoricalRecoveryDisposition;
use App\Enums\CashfreeWebhookFailureCategory;
use App\Models\CashfreeWebhookLog;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CashfreePaymentIntegrityService
{
    public function __construct(
        private readonly CashfreeWebhookPayloadParser $payloadParser,
    ) {}

    public function reconcile(): CashfreePaymentReconciliationReport
    {
        $successfulPayments = $this->successfulPaymentLogsByCfPaymentId();
        $missingOrders = $this->missingPaidOrders($successfulPayments);

        return new CashfreePaymentReconciliationReport(
            successfulCashfreePayments: $successfulPayments->count(),
            deskOrders: Order::query()->whereNotNull('cashfree_payment_id')->count(),
            missingOrdersCount: $missingOrders->count(),
            failedProcessing: CashfreeWebhookLog::query()
                ->where('processing_status', CashfreeWebhookLog::STATUS_FAILED)
                ->get()
                ->filter(fn (CashfreeWebhookLog $log): bool => $this->payloadParser->isSuccessfulPayment($log->request_payload ?? []))
                ->count(),
            paidWithoutDeskOrderCount: $missingOrders->count(),
            missingOrders: $missingOrders
                ->map(fn (array $entry): CashfreeMissingPaidOrderRecord => $this->toMissingRecord($entry))
                ->values()
                ->all(),
        );
    }

    public function paidWithoutDeskOrderCount(): int
    {
        return $this->missingPaidOrders($this->successfulPaymentLogsByCfPaymentId())->count();
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
        $records = CashfreeWebhookLog::query()
            ->where('processing_status', CashfreeWebhookLog::STATUS_FAILED)
            ->orderBy('processed_at')
            ->orderBy('id')
            ->get()
            ->map(fn (CashfreeWebhookLog $log): CashfreeFailedWebhookRecord => $this->classifyFailedLog($log))
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

    public function classifyFailedLog(CashfreeWebhookLog $log): CashfreeFailedWebhookRecord
    {
        $payload = $log->request_payload ?? [];

        if (! $this->payloadParser->isSuccessfulPayment($payload)) {
            return $this->failedWebhookRecord(
                $log,
                CashfreeWebhookFailureCategory::InvalidEvent,
                'payment_not_success',
            );
        }

        $assessment = $this->assessLog($log);

        $category = match ($assessment['reason']) {
            'cashfree_payment_id_exists', 'order_id_exists' => CashfreeWebhookFailureCategory::PaymentExistsInDesk,
            'processed_webhook_exists' => CashfreeWebhookFailureCategory::DuplicateSucceeded,
            'payment_not_success' => CashfreeWebhookFailureCategory::InvalidEvent,
            default => CashfreeWebhookFailureCategory::Unresolved,
        };

        return $this->failedWebhookRecord($log, $category, $assessment['reason']);
    }

    public function requiresCashfreeHealthAlert(): bool
    {
        return $this->paidWithoutDeskOrderCount() > 0
            || $this->activeFailedWebhookCount() > 0;
    }

    /**
     * @return array{log: CashfreeWebhookLog, disposition: CashfreeHistoricalRecoveryDisposition, reason: string}
     */
    public function assessLog(CashfreeWebhookLog $log): array
    {
        $payload = $log->request_payload ?? [];

        if (! $this->payloadParser->isSuccessfulPayment($payload)) {
            return $this->assessment($log, CashfreeHistoricalRecoveryDisposition::Unsafe, 'payment_not_success');
        }

        $cfPaymentId = $this->resolveCfPaymentId($log);

        if ($cfPaymentId === null) {
            return $this->assessment($log, CashfreeHistoricalRecoveryDisposition::Unsafe, 'missing_cf_payment_id');
        }

        if (Order::query()->where('cashfree_payment_id', $cfPaymentId)->exists()) {
            return $this->assessment($log, CashfreeHistoricalRecoveryDisposition::AlreadyExists, 'cashfree_payment_id_exists');
        }

        $businessOrderId = $this->payloadParser->orderId($payload);

        if ($businessOrderId !== null && Order::query()->where('order_id', $businessOrderId)->exists()) {
            return $this->assessment($log, CashfreeHistoricalRecoveryDisposition::AlreadyExists, 'order_id_exists');
        }

        $processedSibling = CashfreeWebhookLog::query()
            ->where('cf_payment_id', $cfPaymentId)
            ->where('id', '!=', $log->id)
            ->where('processing_status', CashfreeWebhookProcessorService::STATUS_PROCESSED)
            ->whereNotNull('incident_id')
            ->exists();

        if ($processedSibling) {
            return $this->assessment($log, CashfreeHistoricalRecoveryDisposition::AlreadyExists, 'processed_webhook_exists');
        }

        if ($businessOrderId === null) {
            return $this->assessment($log, CashfreeHistoricalRecoveryDisposition::Unsafe, 'missing_order_id');
        }

        return $this->assessment($log, CashfreeHistoricalRecoveryDisposition::Recoverable, 'ready');
    }

    /**
     * @return Collection<string, CashfreeWebhookLog>
     */
    public function successfulPaymentLogsByCfPaymentId(): Collection
    {
        /** @var Collection<string, CashfreeWebhookLog> $byPaymentId */
        $byPaymentId = collect();

        CashfreeWebhookLog::query()
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
     * @param  Collection<string, CashfreeWebhookLog>  $successfulPayments
     * @return Collection<int, array{log: CashfreeWebhookLog, disposition: CashfreeHistoricalRecoveryDisposition, reason: string}>
     */
    private function missingPaidOrders(Collection $successfulPayments): Collection
    {
        return $successfulPayments
            ->map(fn (CashfreeWebhookLog $log): array => $this->assessLog($log))
            ->filter(function (array $entry): bool {
                return ! in_array($entry['disposition'], [
                    CashfreeHistoricalRecoveryDisposition::AlreadyExists,
                ], true);
            })
            ->values();
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
