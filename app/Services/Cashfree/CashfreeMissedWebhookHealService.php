<?php

namespace App\Services\Cashfree;

use App\Data\CashfreeMissedBatchHealOrderResult;
use App\Data\CashfreeMissedBatchHealResult;
use App\Enums\CashfreeMissedBatchHealDisposition;
use App\Models\CashfreeWebhookLog;
use App\Models\Order;
use App\Services\AuditLogService;
use App\Services\Cashfree\Exceptions\CashfreeApiException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class CashfreeMissedWebhookHealService
{
    public const INGEST_SOURCE = 'external_reconciliation';

    public const USER_AGENT = 'desk/cashfree-heal-missed-batch';

    public const AUDIT_DISCOVERED = 'cashfree.external_reconcile_discovered';

    public const AUDIT_RECOVERED = 'cashfree.external_reconcile_recovered';

    public const AUDIT_RESUMED = 'cashfree.external_reconcile_resumed';

    public const AUDIT_SKIPPED = 'cashfree.external_reconcile_already_exists';

    public const AUDIT_BLOCKED = 'cashfree.external_reconcile_blocked';

    public const AUDIT_FAILED = 'cashfree.external_reconcile_failed';

    public function __construct(
        private readonly CashfreeApiClient $apiClient,
        private readonly CashfreeWebhookPayloadFactory $payloadFactory,
        private readonly CashfreeWebhookProcessorService $webhookProcessorService,
        private readonly CashfreeWebhookPayloadParser $payloadParser,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * Combined approved targets: Aug 7 allowlist ∪ removable 403-gap allowlist.
     *
     * @return list<string>
     */
    public function allowlist(): array
    {
        return array_values(array_unique([
            ...$this->normalizeIdList(config('cashfree.missed_batch_heal.allowlist', [])),
            ...$this->gapAllowlist(),
        ]));
    }

    /**
     * Removable one-time 403-gap targets. Empty after recovery is complete.
     *
     * @return list<string>
     */
    public function gapAllowlist(): array
    {
        return $this->normalizeIdList(config('cashfree.missed_batch_heal.gap_allowlist', []));
    }

    public function batchId(): string
    {
        $batchId = trim((string) config('cashfree.missed_batch_heal.batch_id', 'aug7-2026-missed-webhook'));

        return $batchId !== '' ? $batchId : 'aug7-2026-missed-webhook';
    }

    public function gapBatchId(): string
    {
        $batchId = trim((string) config('cashfree.missed_batch_heal.gap_batch_id', 'aug10-2026-403-webhook-gap'));

        return $batchId !== '' ? $batchId : 'aug10-2026-403-webhook-gap';
    }

    public function batchIdFor(string $orderId): string
    {
        return in_array($orderId, $this->gapAllowlist(), true)
            ? $this->gapBatchId()
            : $this->batchId();
    }

    /**
     * @param  list<string>|null  $orderIds
     */
    public function heal(?array $orderIds = null, bool $dryRun = true): CashfreeMissedBatchHealResult
    {
        $targets = $this->resolveTargets($orderIds);

        if (! $dryRun) {
            $this->apiClient->assertConfigured();

            $lockSeconds = max(30, (int) config('cashfree.missed_batch_heal.lock_seconds', 120));
            $lock = Cache::lock('cashfree:heal-missed-batch', $lockSeconds);

            if (! $lock->get()) {
                throw new InvalidArgumentException(
                    'Another cashfree:heal-missed-batch execute run is already in progress.',
                );
            }

            try {
                return $this->runTargets($targets, dryRun: false);
            } finally {
                $lock->release();
            }
        }

        $this->apiClient->assertConfigured();

        return $this->runTargets($targets, dryRun: true);
    }

    /**
     * @param  list<string>  $targets
     */
    private function runTargets(array $targets, bool $dryRun): CashfreeMissedBatchHealResult
    {
        $results = [];
        $wouldHeal = 0;
        $healed = 0;
        $resumed = 0;
        $skipped = 0;
        $blocked = 0;
        $failed = 0;

        foreach ($targets as $orderId) {
            $result = $this->healOne($orderId, $dryRun);
            $results[] = $result;

            match ($result->disposition) {
                CashfreeMissedBatchHealDisposition::WouldHeal => $wouldHeal++,
                CashfreeMissedBatchHealDisposition::Healed => $healed++,
                CashfreeMissedBatchHealDisposition::Resumed => $resumed++,
                CashfreeMissedBatchHealDisposition::Skipped => $skipped++,
                CashfreeMissedBatchHealDisposition::Blocked => $blocked++,
                CashfreeMissedBatchHealDisposition::Failed => $failed++,
            };
        }

        return new CashfreeMissedBatchHealResult(
            dryRun: $dryRun,
            orders: $results,
            wouldHeal: $wouldHeal,
            healed: $healed,
            resumed: $resumed,
            skipped: $skipped,
            blocked: $blocked,
            failed: $failed,
        );
    }

    private function healOne(string $orderId, bool $dryRun): CashfreeMissedBatchHealOrderResult
    {
        try {
            $orderEntity = $this->apiClient->getOrder($orderId);
            $payments = $this->apiClient->getOrderPayments($orderId);
            $payment = $this->selectSuccessPayment($orderId, $payments);

            if ($payment instanceof CashfreeMissedBatchHealOrderResult) {
                return $payment;
            }

            $cfPaymentId = $this->scalar($payment['cf_payment_id'] ?? null);

            if ($cfPaymentId === null) {
                return $this->blocked($orderId, 'missing_cf_payment_id');
            }

            $orderStatus = strtoupper((string) ($this->scalar($orderEntity['order_status'] ?? null) ?? ''));

            if ($orderStatus !== 'PAID') {
                return $this->blocked($orderId, 'order_not_paid', $cfPaymentId);
            }

            $ownership = $this->assessDeskOwnership($orderId, $cfPaymentId);

            if ($ownership !== null) {
                return $ownership;
            }

            $payload = $this->payloadFactory->fromOrderAndSuccessPayment($orderEntity, $payment);
            $expectedSerial = $this->payloadParser->orderTagSerialNo($payload);

            $existingLog = $this->findResumableWebhookLog($cfPaymentId);

            if ($existingLog !== null) {
                return $this->resumeExistingLog($existingLog, $orderId, $cfPaymentId, $payload, $expectedSerial, $dryRun);
            }

            if ($dryRun) {
                Log::info('[Cashfree Missed Batch Heal] Dry-run would heal order.', [
                    'order_id' => $orderId,
                    'cf_payment_id' => $cfPaymentId,
                    'batch' => $this->batchIdFor($orderId),
                    'expected_serial' => $expectedSerial,
                ]);

                return new CashfreeMissedBatchHealOrderResult(
                    orderId: $orderId,
                    disposition: CashfreeMissedBatchHealDisposition::WouldHeal,
                    reason: 'ready',
                    cfPaymentId: $cfPaymentId,
                    payload: $payload,
                    expectedSerial: $expectedSerial,
                );
            }

            return $this->insertAndProcess($orderId, $cfPaymentId, $payload, $expectedSerial);
        } catch (CashfreeApiException $exception) {
            return $this->failed($orderId, 'cashfree_api: '.$exception->getMessage());
        } catch (Throwable $exception) {
            Log::error('[Cashfree Missed Batch Heal] Unexpected failure.', [
                'order_id' => $orderId,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return $this->failed($orderId, 'unexpected: '.$exception->getMessage());
        }
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     * @return array<string, mixed>|CashfreeMissedBatchHealOrderResult
     */
    private function selectSuccessPayment(string $orderId, array $payments): array|CashfreeMissedBatchHealOrderResult
    {
        $success = [];

        foreach ($payments as $payment) {
            $status = strtoupper((string) ($this->scalar($payment['payment_status'] ?? null) ?? ''));

            if ($status !== CashfreeWebhookPayloadParser::PAYMENT_STATUS_SUCCESS) {
                continue;
            }

            if ($this->scalar($payment['cf_payment_id'] ?? null) === null) {
                continue;
            }

            $success[] = $payment;
        }

        if ($success === []) {
            $hasSuccessWithoutId = false;

            foreach ($payments as $payment) {
                $status = strtoupper((string) ($this->scalar($payment['payment_status'] ?? null) ?? ''));

                if ($status === CashfreeWebhookPayloadParser::PAYMENT_STATUS_SUCCESS) {
                    $hasSuccessWithoutId = true;
                    break;
                }
            }

            if ($hasSuccessWithoutId) {
                return $this->blocked($orderId, 'missing_cf_payment_id');
            }

            return $this->blocked($orderId, 'missing_success_payment');
        }

        usort($success, function (array $left, array $right): int {
            $leftTime = (string) ($left['payment_time'] ?? $left['payment_completion_time'] ?? '');
            $rightTime = (string) ($right['payment_time'] ?? $right['payment_completion_time'] ?? '');

            return $leftTime <=> $rightTime;
        });

        return $success[0];
    }

    private function assessDeskOwnership(string $orderId, string $cfPaymentId): ?CashfreeMissedBatchHealOrderResult
    {
        $orderById = Order::query()->where('order_id', $orderId)->first();
        $orderByPayment = Order::query()->where('cashfree_payment_id', $cfPaymentId)->first();

        if ($orderById !== null && $orderByPayment !== null && $orderById->id !== $orderByPayment->id) {
            return $this->blocked(
                $orderId,
                'order_id_and_cf_payment_id_conflict',
                $cfPaymentId,
                deskOrderId: $orderById->id,
            );
        }

        if ($orderByPayment !== null && $orderByPayment->order_id !== $orderId) {
            return $this->blocked(
                $orderId,
                'cf_payment_id_owned_by_other_order',
                $cfPaymentId,
                deskOrderId: $orderByPayment->id,
            );
        }

        if ($orderById !== null) {
            $existingPaymentId = $this->scalar($orderById->cashfree_payment_id);

            if ($existingPaymentId !== null && $existingPaymentId !== $cfPaymentId) {
                $this->auditModel($orderById, self::AUDIT_BLOCKED, [
                    'order_id' => $orderId,
                    'cf_payment_id' => $cfPaymentId,
                    'batch' => $this->batchIdFor($orderId),
                    'source' => self::INGEST_SOURCE,
                    'reason' => 'order_id_exists_with_different_cf_payment_id',
                ]);

                return $this->blocked(
                    $orderId,
                    'order_id_exists_with_different_cf_payment_id',
                    $cfPaymentId,
                    deskOrderId: $orderById->id,
                );
            }

            $this->auditModel($orderById, self::AUDIT_SKIPPED, [
                'order_id' => $orderId,
                'cf_payment_id' => $cfPaymentId,
                'batch' => $this->batchIdFor($orderId),
                'source' => self::INGEST_SOURCE,
                'reason' => 'desk_order_exists',
            ]);

            return $this->skipped(
                $orderId,
                'desk_order_exists',
                $cfPaymentId,
                deskOrderId: $orderById->id,
            );
        }

        if ($orderByPayment !== null) {
            $this->auditModel($orderByPayment, self::AUDIT_SKIPPED, [
                'order_id' => $orderId,
                'cf_payment_id' => $cfPaymentId,
                'batch' => $this->batchIdFor($orderId),
                'source' => self::INGEST_SOURCE,
                'reason' => 'desk_cf_payment_id_exists',
            ]);

            return $this->skipped(
                $orderId,
                'desk_cf_payment_id_exists',
                $cfPaymentId,
                deskOrderId: $orderByPayment->id,
            );
        }

        $processedLog = CashfreeWebhookLog::query()
            ->where('cf_payment_id', $cfPaymentId)
            ->where('processing_status', CashfreeWebhookProcessorService::STATUS_PROCESSED)
            ->latest('id')
            ->first();

        if ($processedLog !== null) {
            $this->audit($processedLog, self::AUDIT_SKIPPED, [
                'order_id' => $orderId,
                'cf_payment_id' => $cfPaymentId,
                'batch' => $this->batchIdFor($orderId),
                'source' => self::INGEST_SOURCE,
                'reason' => 'processed_webhook_exists',
                'webhook_log_id' => $processedLog->id,
            ]);

            return $this->skipped(
                $orderId,
                'processed_webhook_exists',
                $cfPaymentId,
                webhookLogId: $processedLog->id,
            );
        }

        return null;
    }

    private function findResumableWebhookLog(string $cfPaymentId): ?CashfreeWebhookLog
    {
        return CashfreeWebhookLog::query()
            ->where('cf_payment_id', $cfPaymentId)
            ->whereIn('processing_status', [
                CashfreeWebhookLog::STATUS_RECEIVED,
                CashfreeWebhookLog::STATUS_FAILED,
            ])
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resumeExistingLog(
        CashfreeWebhookLog $log,
        string $orderId,
        string $cfPaymentId,
        array $payload,
        ?string $expectedSerial,
        bool $dryRun,
    ): CashfreeMissedBatchHealOrderResult {
        if ($dryRun) {
            return new CashfreeMissedBatchHealOrderResult(
                orderId: $orderId,
                disposition: CashfreeMissedBatchHealDisposition::WouldHeal,
                reason: 'resume_existing_webhook_log',
                cfPaymentId: $cfPaymentId,
                payload: $payload,
                webhookLogId: $log->id,
                expectedSerial: $expectedSerial,
            );
        }

        $processed = $this->webhookProcessorService->process($log->fresh() ?? $log);

        $this->audit($processed, self::AUDIT_RESUMED, [
            'order_id' => $orderId,
            'cf_payment_id' => $cfPaymentId,
            'batch' => $this->batchIdFor($orderId),
            'source' => self::INGEST_SOURCE,
            'webhook_log_id' => $processed->id,
            'processing_status' => $processed->processing_status,
        ]);

        if ($processed->processing_status !== CashfreeWebhookProcessorService::STATUS_PROCESSED) {
            return $this->failed(
                $orderId,
                'resume_process_failed: '.($processed->processing_error ?? 'unknown'),
                $cfPaymentId,
                webhookLogId: $processed->id,
                payload: $payload,
            );
        }

        $deskOrder = Order::query()->where('cashfree_payment_id', $cfPaymentId)->first();

        return new CashfreeMissedBatchHealOrderResult(
            orderId: $orderId,
            disposition: CashfreeMissedBatchHealDisposition::Resumed,
            reason: 'processed_existing_webhook_log',
            cfPaymentId: $cfPaymentId,
            payload: $payload,
            webhookLogId: $processed->id,
            deskOrderId: $deskOrder?->id,
            expectedSerial: $expectedSerial,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function insertAndProcess(
        string $orderId,
        string $cfPaymentId,
        array $payload,
        ?string $expectedSerial,
    ): CashfreeMissedBatchHealOrderResult {
        // Re-check ownership immediately before write (race with live webhook).
        $race = $this->assessDeskOwnership($orderId, $cfPaymentId);

        if ($race !== null) {
            return $race;
        }

        $existingLog = $this->findResumableWebhookLog($cfPaymentId);

        if ($existingLog !== null) {
            return $this->resumeExistingLog(
                $existingLog,
                $orderId,
                $cfPaymentId,
                $payload,
                $expectedSerial,
                dryRun: false,
            );
        }

        $headers = $this->syntheticHeaders($orderId);

        $log = CashfreeWebhookLog::query()->create([
            'webhook_version' => (string) config('cashfree.api.version', '2026-01-01'),
            'cf_payment_id' => $cfPaymentId,
            'request_headers' => $headers,
            'request_payload' => $payload,
            'received_at' => now(),
            'source_ip' => '127.0.0.1',
            'user_agent' => self::USER_AGENT,
            'processing_status' => CashfreeWebhookLog::STATUS_RECEIVED,
        ]);

        $this->audit($log, self::AUDIT_DISCOVERED, [
            'order_id' => $orderId,
            'cf_payment_id' => $cfPaymentId,
            'batch' => $this->batchIdFor($orderId),
            'source' => self::INGEST_SOURCE,
            'webhook_log_id' => $log->id,
            'mode' => 'execute',
        ]);

        $processed = $this->webhookProcessorService->process($log->fresh() ?? $log);

        if ($processed->processing_status !== CashfreeWebhookProcessorService::STATUS_PROCESSED) {
            $this->audit($processed, self::AUDIT_FAILED, [
                'order_id' => $orderId,
                'cf_payment_id' => $cfPaymentId,
                'batch' => $this->batchIdFor($orderId),
                'source' => self::INGEST_SOURCE,
                'webhook_log_id' => $processed->id,
                'processing_error' => $processed->processing_error,
            ]);

            return $this->failed(
                $orderId,
                'process_failed: '.($processed->processing_error ?? 'unknown'),
                $cfPaymentId,
                webhookLogId: $processed->id,
                payload: $payload,
            );
        }

        $deskOrder = Order::query()->where('cashfree_payment_id', $cfPaymentId)->first();

        $this->audit($processed, self::AUDIT_RECOVERED, [
            'order_id' => $orderId,
            'cf_payment_id' => $cfPaymentId,
            'batch' => $this->batchIdFor($orderId),
            'source' => self::INGEST_SOURCE,
            'webhook_log_id' => $processed->id,
            'desk_order_id' => $deskOrder?->id,
            'serial_number' => $deskOrder?->serial_number,
        ]);

        return new CashfreeMissedBatchHealOrderResult(
            orderId: $orderId,
            disposition: CashfreeMissedBatchHealDisposition::Healed,
            reason: 'synthetic_webhook_processed',
            cfPaymentId: $cfPaymentId,
            payload: $payload,
            webhookLogId: $processed->id,
            deskOrderId: $deskOrder?->id,
            expectedSerial: $expectedSerial,
        );
    }

    /**
     * @return array<string, list<string>>
     */
    public function syntheticHeaders(string $orderId): array
    {
        $batchId = $this->batchIdFor($orderId);

        return [
            'X-Desk-Ingest-Source' => [self::INGEST_SOURCE],
            'X-Desk-Reconcile-Batch' => [$batchId],
            'X-Desk-Reconcile-Reason' => [$batchId],
            'User-Agent' => [self::USER_AGENT],
        ];
    }

    /**
     * @param  list<string>|null  $orderIds
     * @return list<string>
     */
    private function resolveTargets(?array $orderIds): array
    {
        $allowlist = $this->allowlist();

        if ($allowlist === []) {
            throw new InvalidArgumentException('Cashfree missed-batch allowlist is empty.');
        }

        if ($orderIds === null || $orderIds === []) {
            return $allowlist;
        }

        $normalized = [];

        foreach ($orderIds as $orderId) {
            $id = trim((string) $orderId);

            if ($id === '') {
                continue;
            }

            if (! in_array($id, $allowlist, true)) {
                throw new InvalidArgumentException(
                    'Order '.$id.' is not in the approved missed-webhook heal allowlist.',
                );
            }

            $normalized[$id] = $id;
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('No valid allowlisted order IDs were provided.');
        }

        return array_values($normalized);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function skipped(
        string $orderId,
        string $reason,
        ?string $cfPaymentId = null,
        ?int $deskOrderId = null,
        ?int $webhookLogId = null,
        ?array $payload = null,
    ): CashfreeMissedBatchHealOrderResult {
        return new CashfreeMissedBatchHealOrderResult(
            orderId: $orderId,
            disposition: CashfreeMissedBatchHealDisposition::Skipped,
            reason: $reason,
            cfPaymentId: $cfPaymentId,
            payload: $payload,
            webhookLogId: $webhookLogId,
            deskOrderId: $deskOrderId,
        );
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function blocked(
        string $orderId,
        string $reason,
        ?string $cfPaymentId = null,
        ?int $deskOrderId = null,
        ?array $payload = null,
    ): CashfreeMissedBatchHealOrderResult {
        return new CashfreeMissedBatchHealOrderResult(
            orderId: $orderId,
            disposition: CashfreeMissedBatchHealDisposition::Blocked,
            reason: $reason,
            cfPaymentId: $cfPaymentId,
            payload: $payload,
            deskOrderId: $deskOrderId,
        );
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function failed(
        string $orderId,
        string $reason,
        ?string $cfPaymentId = null,
        ?int $webhookLogId = null,
        ?array $payload = null,
    ): CashfreeMissedBatchHealOrderResult {
        return new CashfreeMissedBatchHealOrderResult(
            orderId: $orderId,
            disposition: CashfreeMissedBatchHealDisposition::Failed,
            reason: $reason,
            cfPaymentId: $cfPaymentId,
            payload: $payload,
            webhookLogId: $webhookLogId,
        );
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function audit(CashfreeWebhookLog $log, string $event, array $newValues): void
    {
        $this->auditModel($log, $event, $newValues);
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function auditModel(Model $model, string $event, array $newValues): void
    {
        $this->auditLogService->log(
            userId: null,
            event: $event,
            auditable: $model,
            oldValues: null,
            newValues: $newValues,
        );
    }

    private function scalar(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * @return list<string>
     */
    private function normalizeIdList(mixed $configured): array
    {
        if (! is_array($configured) || $configured === []) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $configured,
        ), static fn (string $id): bool => $id !== ''));
    }
}
