<?php

namespace App\Services\Cashfree;

use App\Data\CashfreeHistoricalRecoveryResult;
use App\Enums\CashfreeHistoricalRecoveryDisposition;
use App\Models\CashfreeWebhookLog;
use App\Models\Order;
use Illuminate\Support\Collection;

class CashfreeHistoricalRecoveryService
{
    public function __construct(
        private readonly CashfreeWebhookProcessorService $webhookProcessorService,
        private readonly CashfreeWebhookPayloadParser $payloadParser,
    ) {}

    public function recover(bool $dryRun = false, ?int $singleLogId = null): CashfreeHistoricalRecoveryResult
    {
        $candidates = $singleLogId !== null
            ? $this->candidateForSingleLog($singleLogId)
            : $this->recoveryCandidates();

        $recoverable = 0;
        $alreadyExists = 0;
        $unsafe = 0;
        $recovered = 0;
        $stillFailed = 0;

        foreach ($candidates as $candidate) {
            match ($candidate['disposition']) {
                CashfreeHistoricalRecoveryDisposition::Recoverable => $recoverable++,
                CashfreeHistoricalRecoveryDisposition::AlreadyExists => $alreadyExists++,
                CashfreeHistoricalRecoveryDisposition::Unsafe => $unsafe++,
            };

            if ($dryRun || $candidate['disposition'] !== CashfreeHistoricalRecoveryDisposition::Recoverable) {
                continue;
            }

            $result = $this->webhookProcessorService->process($candidate['log']->fresh());

            if ($result->processing_status === CashfreeWebhookProcessorService::STATUS_PROCESSED) {
                $recovered++;

                continue;
            }

            $stillFailed++;
        }

        return new CashfreeHistoricalRecoveryResult(
            found: $candidates->count(),
            recoverable: $recoverable,
            alreadyExists: $alreadyExists,
            unsafe: $unsafe,
            recovered: $recovered,
            stillFailed: $stillFailed,
        );
    }

    /**
     * @return Collection<int, array{log: CashfreeWebhookLog, disposition: CashfreeHistoricalRecoveryDisposition, reason: string}>
     */
    private function recoveryCandidates(): Collection
    {
        $logs = CashfreeWebhookLog::query()
            ->where('processing_status', CashfreeWebhookLog::STATUS_FAILED)
            ->orderBy('received_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (CashfreeWebhookLog $log): bool => $this->payloadParser->isSuccessfulPayment($log->request_payload ?? []));

        /** @var Collection<string, CashfreeWebhookLog> $earliestByPayment */
        $earliestByPayment = collect();

        foreach ($logs as $log) {
            $groupKey = $this->groupKeyForLog($log);

            if (! $earliestByPayment->has($groupKey)) {
                $earliestByPayment->put($groupKey, $log);
            }
        }

        return $earliestByPayment
            ->values()
            ->map(fn (CashfreeWebhookLog $log): array => $this->assessLog($log))
            ->values();
    }

    /**
     * @return Collection<int, array{log: CashfreeWebhookLog, disposition: CashfreeHistoricalRecoveryDisposition, reason: string}>
     */
    private function candidateForSingleLog(int $logId): Collection
    {
        $log = CashfreeWebhookLog::query()->find($logId);

        if ($log === null) {
            return collect();
        }

        return collect([$this->assessLog($log)]);
    }

    /**
     * @return array{log: CashfreeWebhookLog, disposition: CashfreeHistoricalRecoveryDisposition, reason: string}
     */
    private function assessLog(CashfreeWebhookLog $log): array
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

    private function groupKeyForLog(CashfreeWebhookLog $log): string
    {
        $cfPaymentId = $this->resolveCfPaymentId($log);

        if ($cfPaymentId !== null) {
            return 'cf:'.$cfPaymentId;
        }

        return 'log:'.$log->id;
    }

    private function resolveCfPaymentId(CashfreeWebhookLog $log): ?string
    {
        $payload = $log->request_payload ?? [];

        return $this->payloadParser->cfPaymentId($payload) ?? $log->cf_payment_id;
    }
}
