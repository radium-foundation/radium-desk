<?php

namespace App\Services\Cashfree;

use App\Data\CashfreeHistoricalRecoveryResult;
use App\Data\Operations\IraCommunicationInput;
use App\Enums\CashfreeHistoricalRecoveryDisposition;
use App\Enums\IraNotificationType;
use App\Models\CashfreeWebhookLog;
use App\Services\AuditLogService;
use App\Services\Operations\IraCommunicationService;
use Illuminate\Support\Facades\Log;
use Throwable;

class CashfreeMissingOrderAutoRecoveryService
{
    public const AUDIT_EVENT = 'cashfree.missing_order_auto_recovery';

    public function __construct(
        private readonly CashfreePaymentIntegrityService $integrityService,
        private readonly CashfreeHistoricalRecoveryService $historicalRecoveryService,
        private readonly CashfreeWebhookPayloadParser $payloadParser,
        private readonly AuditLogService $auditLogService,
        private readonly IraCommunicationService $iraCommunicationService,
    ) {}

    public function run(?int $maxPerRun = null): CashfreeHistoricalRecoveryResult
    {
        $limit = $maxPerRun ?? max(1, (int) config('cashfree.auto_recover.max_per_run', 20));
        $candidates = $this->recoverableCandidates($limit);

        if ($candidates->isEmpty()) {
            return new CashfreeHistoricalRecoveryResult(
                found: 0,
                recoverable: 0,
                alreadyExists: 0,
                unsafe: 0,
                recovered: 0,
                stillFailed: 0,
            );
        }

        $recovered = 0;
        $stillFailed = 0;
        $failedLogIds = [];

        foreach ($candidates as $candidate) {
            $log = $candidate['log'];

            try {
                $result = $this->historicalRecoveryService->recover(dryRun: false, singleLogId: $log->id);
            } catch (Throwable $exception) {
                $stillFailed++;
                $failedLogIds[] = $log->id;

                Log::error('[Cashfree Auto Recovery] Exception while recovering webhook.', [
                    'webhook_log_id' => $log->id,
                    'cf_payment_id' => $log->cf_payment_id,
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);

                continue;
            }

            if ($result->recovered > 0) {
                $recovered += $result->recovered;
                $this->auditRecovery($log, recovered: true, stillFailed: false);

                continue;
            }

            if ($result->stillFailed > 0 || $result->recoverable > 0) {
                $stillFailed++;
                $failedLogIds[] = $log->id;
                $this->auditRecovery($log, recovered: false, stillFailed: true);

                continue;
            }

            // AlreadyExists / Unsafe after re-assessment — no action needed.
            $this->auditRecovery($log, recovered: false, stillFailed: false, note: $result->alreadyExists > 0 ? 'already_exists' : 'unsafe');
        }

        $summary = new CashfreeHistoricalRecoveryResult(
            found: $candidates->count(),
            recoverable: $candidates->count(),
            alreadyExists: 0,
            unsafe: 0,
            recovered: $recovered,
            stillFailed: $stillFailed,
        );

        Log::info('[Cashfree Auto Recovery] Run completed.', [
            'found' => $summary->found,
            'recovered' => $summary->recovered,
            'still_failed' => $summary->stillFailed,
            'failed_log_ids' => $failedLogIds,
        ]);

        if ($stillFailed > 0) {
            $this->notifyRecoveryFailure($failedLogIds, $summary);
        }

        return $summary;
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{log: CashfreeWebhookLog, disposition: CashfreeHistoricalRecoveryDisposition, reason: string}>
     */
    private function recoverableCandidates(int $limit)
    {
        return collect($this->integrityService->reconcile()->missingOrders)
            ->filter(fn ($record): bool => $record->recoveryEligibility === CashfreeHistoricalRecoveryDisposition::Recoverable)
            ->take($limit)
            ->map(function ($record): ?array {
                $log = CashfreeWebhookLog::query()->find($record->webhookLogId);

                if ($log === null) {
                    return null;
                }

                return $this->integrityService->assessLog($log);
            })
            ->filter(fn (?array $entry): bool => $entry !== null
                && $entry['disposition'] === CashfreeHistoricalRecoveryDisposition::Recoverable)
            ->values();
    }

    private function auditRecovery(
        CashfreeWebhookLog $log,
        bool $recovered,
        bool $stillFailed,
        ?string $note = null,
    ): void {
        $payload = $log->request_payload ?? [];

        $this->auditLogService->log(
            userId: null,
            event: self::AUDIT_EVENT,
            auditable: $log,
            oldValues: [
                'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            ],
            newValues: [
                'recovered' => $recovered,
                'still_failed' => $stillFailed,
                'note' => $note,
                'cf_payment_id' => $this->payloadParser->cfPaymentId($payload) ?? $log->cf_payment_id,
                'order_id' => $this->payloadParser->orderId($payload),
                'processing_status' => $log->fresh()?->processing_status,
            ],
        );
    }

    /**
     * @param  list<int>  $failedLogIds
     */
    private function notifyRecoveryFailure(array $failedLogIds, CashfreeHistoricalRecoveryResult $summary): void
    {
        $ids = implode(', ', array_map('strval', $failedLogIds));

        try {
            $this->iraCommunicationService->dispatch(new IraCommunicationInput(
                event: IraNotificationType::IntegrationFailure,
                context: [
                    'label' => 'Cashfree',
                    'message' => sprintf(
                        'Auto-recovery failed for %d paid payment(s). Webhook log(s): %s. Run cashfree:reconcile and cashfree:recover-historical.',
                        $summary->stillFailed,
                        $ids !== '' ? $ids : 'unknown',
                    ),
                    'dedupe_key' => 'cashfree:auto_recover_failed:'.md5($ids),
                ],
            ));
        } catch (Throwable $exception) {
            Log::error('[Cashfree Auto Recovery] Failed to notify Ira/admin.', [
                'failed_log_ids' => $failedLogIds,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
