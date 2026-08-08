<?php

namespace App\Services\Cashfree;

use App\Data\CashfreeHistoricalRecoveryResult;
use App\Enums\CashfreeHistoricalRecoveryDisposition;
use App\Models\CashfreeWebhookLog;
use Illuminate\Support\Collection;

class CashfreeHistoricalRecoveryService
{
    public function __construct(
        private readonly CashfreeWebhookProcessorService $webhookProcessorService,
        private readonly CashfreeWebhookPayloadParser $payloadParser,
        private readonly CashfreePaymentIntegrityService $integrityService,
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
     * Live discovery for auto-recovery.
     *
     * Universe: earliest successful PAYMENT_SUCCESS per payment among non-processed
     * webhook logs (`failed` and stuck `received`). Aligns with integrity reconcile
     * recoverable IDs without scanning the full webhook history table.
     *
     * @return Collection<int, array{log: CashfreeWebhookLog, disposition: CashfreeHistoricalRecoveryDisposition, reason: string}>
     */
    public function autoRecoveryCandidateAssessments(): Collection
    {
        return $this->discoveryCandidateAssessments(includeReceived: true);
    }

    /**
     * @return Collection<int, array{log: CashfreeWebhookLog, disposition: CashfreeHistoricalRecoveryDisposition, reason: string}>
     */
    private function recoveryCandidates(): Collection
    {
        return $this->discoveryCandidateAssessments(includeReceived: false);
    }

    /**
     * @return Collection<int, array{log: CashfreeWebhookLog, disposition: CashfreeHistoricalRecoveryDisposition, reason: string}>
     */
    private function discoveryCandidateAssessments(bool $includeReceived): Collection
    {
        $statuses = [CashfreeWebhookLog::STATUS_FAILED];

        if ($includeReceived) {
            $statuses[] = CashfreeWebhookLog::STATUS_RECEIVED;
        }

        if (! $this->hasRecoveryDiscoveryRows($statuses)) {
            return collect();
        }

        $logs = CashfreeWebhookLog::query()
            ->select([
                'id',
                'cf_payment_id',
                'request_payload',
                'received_at',
                'processing_status',
            ])
            ->whereIn('processing_status', $statuses)
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
            ->map(fn (CashfreeWebhookLog $log): array => $this->integrityService->assessLog($log))
            ->values();
    }

    /**
     * @param  list<string>  $statuses
     */
    private function hasRecoveryDiscoveryRows(array $statuses): bool
    {
        return CashfreeWebhookLog::query()
            ->whereIn('processing_status', $statuses)
            ->exists();
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

        return collect([$this->integrityService->assessLog($log)]);
    }

    private function groupKeyForLog(CashfreeWebhookLog $log): string
    {
        $payload = $log->request_payload ?? [];
        $cfPaymentId = $this->payloadParser->cfPaymentId($payload) ?? $log->cf_payment_id;

        if ($cfPaymentId !== null) {
            return 'cf:'.$cfPaymentId;
        }

        return 'log:'.$log->id;
    }
}
