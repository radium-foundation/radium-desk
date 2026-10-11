<?php

namespace App\Services\Cashfree;

use App\Data\Cashfree\LegacyCashfreeExistingOrderLinkMetadata;
use App\Data\Cashfree\LegacyCashfreeExistingOrderLinkOutcome;
use App\Models\CashfreeWebhookLog;
use App\Models\FinanceJournal;
use App\Models\Order;
use App\Services\Cashfree\Exceptions\CashfreeApiException;
use App\Services\RefundCalculationService;
use Throwable;

/**
 * Shared P-04-10-194 / P-04-10-197 existing-order Cashfree payment link executor.
 */
final class LegacyCashfreeExistingOrderPaymentLinker
{
    public function __construct(
        private readonly CashfreeApiClient $apiClient,
        private readonly CashfreeWebhookPayloadFactory $payloadFactory,
        private readonly CashfreeWebhookProcessorService $webhookProcessorService,
        private readonly RefundCalculationService $refundCalculationService,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function getOrderRefundsWithRetry(string $orderNumber): array
    {
        $attempts = 0;
        $maxAttempts = 6;

        while (true) {
            $attempts++;

            try {
                return $this->apiClient->getOrderRefunds($orderNumber);
            } catch (CashfreeApiException $exception) {
                if ($attempts >= $maxAttempts || ! str_contains($exception->getMessage(), 'HTTP 429')) {
                    throw $exception;
                }

                usleep((int) (500_000 * (2 ** ($attempts - 1))));
            }
        }
    }

    public function orderHasAuthoritativePayment(Order $order): bool
    {
        $amount = round((float) ($order->payment_amount ?? 0), 2);
        $cf = trim((string) ($order->cashfree_payment_id ?? ''));

        return $amount > 0 && $cf !== '';
    }

    public function linkVerifiedSuccessPayment(
        Order $order,
        array $cashfreeOrder,
        array $paymentEntity,
        LegacyCashfreeExistingOrderLinkMetadata $metadata,
    ): LegacyCashfreeExistingOrderLinkOutcome {
        $cfPaymentId = trim((string) ($paymentEntity['cf_payment_id'] ?? ''));
        $amount = round((float) ($paymentEntity['payment_amount'] ?? 0), 2);

        if ($cfPaymentId === '' || $amount <= 0) {
            return new LegacyCashfreeExistingOrderLinkOutcome(
                success: false,
                alreadyLinked: false,
                reason: 'Invalid Cashfree payment entity for linking.',
            );
        }

        $existing = $this->assessAlreadyLinked($order, $cfPaymentId, $amount);

        if ($existing !== null) {
            return $existing;
        }

        if ($this->hasConflictingDeskPayment($order, $cfPaymentId)) {
            return new LegacyCashfreeExistingOrderLinkOutcome(
                success: false,
                alreadyLinked: false,
                reason: 'Desk already has conflicting payment authority.',
            );
        }

        try {
            $payload = $this->payloadFactory->fromOrderAndSuccessPayment($cashfreeOrder, $paymentEntity);
            $log = $this->resolveWebhookLog($cfPaymentId, $payload, $metadata);
            $processed = $this->webhookProcessorService->process($log->fresh() ?? $log);

            if ($processed->processing_status !== CashfreeWebhookProcessorService::STATUS_PROCESSED) {
                return new LegacyCashfreeExistingOrderLinkOutcome(
                    success: false,
                    alreadyLinked: false,
                    reason: 'Webhook processor did not mark log processed: '.($processed->processing_error ?? 'unknown'),
                    details: ['webhook_log_id' => $processed->id],
                );
            }
        } catch (Throwable $exception) {
            return new LegacyCashfreeExistingOrderLinkOutcome(
                success: false,
                alreadyLinked: false,
                reason: $exception->getMessage(),
            );
        }

        return $this->postValidateLinked($order->fresh() ?? $order, $cfPaymentId, $amount);
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     */
    public function findSuccessPayment(array $payments, string $cfPaymentId): ?array
    {
        foreach ($payments as $payment) {
            if (! is_array($payment)) {
                continue;
            }

            $id = trim((string) ($payment['cf_payment_id'] ?? ''));
            $status = strtoupper((string) ($payment['payment_status'] ?? ''));

            if ($id === $cfPaymentId && $status === CashfreeWebhookPayloadParser::PAYMENT_STATUS_SUCCESS) {
                return $payment;
            }
        }

        return null;
    }

    private function assessAlreadyLinked(
        Order $order,
        string $cfPaymentId,
        float $amount,
    ): ?LegacyCashfreeExistingOrderLinkOutcome {
        $deskCf = trim((string) ($order->cashfree_payment_id ?? ''));
        $deskAmount = round((float) ($order->payment_amount ?? 0), 2);

        if ($deskCf === '' && $deskAmount <= 0) {
            return null;
        }

        if ($deskCf !== $cfPaymentId) {
            return null;
        }

        if (abs($deskAmount - $amount) >= 0.01) {
            return new LegacyCashfreeExistingOrderLinkOutcome(
                success: false,
                alreadyLinked: false,
                reason: 'Desk cashfree_payment_id matches but payment_amount differs.',
            );
        }

        $journal = FinanceJournal::query()
            ->where('idempotency_key', 'order_payment:'.$order->id)
            ->first();

        return new LegacyCashfreeExistingOrderLinkOutcome(
            success: true,
            alreadyLinked: true,
            reason: 'Desk payment authority already satisfied.',
            details: [
                'cf_payment_id' => $cfPaymentId,
                'finance_journal_id' => $journal?->id,
                'idempotency_key' => 'order_payment:'.$order->id,
                'maximum_refundable' => $this->refundCalculationService->maximumRefundable($order),
            ],
        );
    }

    private function hasConflictingDeskPayment(Order $order, string $expectedPaymentId): bool
    {
        $deskCf = trim((string) ($order->cashfree_payment_id ?? ''));
        $deskAmount = round((float) ($order->payment_amount ?? 0), 2);

        if ($deskCf !== '' && $deskCf !== $expectedPaymentId) {
            return true;
        }

        return $deskAmount > 0 && $deskCf === '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveWebhookLog(
        string $cfPaymentId,
        array $payload,
        LegacyCashfreeExistingOrderLinkMetadata $metadata,
    ): CashfreeWebhookLog {
        $existing = CashfreeWebhookLog::query()
            ->where('cf_payment_id', $cfPaymentId)
            ->whereIn('processing_status', [
                CashfreeWebhookLog::STATUS_RECEIVED,
                CashfreeWebhookLog::STATUS_FAILED,
            ])
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $processed = CashfreeWebhookLog::query()
            ->where('cf_payment_id', $cfPaymentId)
            ->where('processing_status', CashfreeWebhookProcessorService::STATUS_PROCESSED)
            ->latest('id')
            ->first();

        if ($processed !== null) {
            return $processed;
        }

        return CashfreeWebhookLog::query()->create([
            'webhook_version' => (string) config('cashfree.api.version', '2026-01-01'),
            'cf_payment_id' => $cfPaymentId,
            'request_headers' => [
                'X-Desk-Ingest-Source' => [$metadata->ingestSource],
                'X-Desk-Prompt-Id' => [$metadata->promptId],
                'User-Agent' => [$metadata->userAgent],
            ],
            'request_payload' => $payload,
            'received_at' => now(),
            'source_ip' => '127.0.0.1',
            'user_agent' => $metadata->userAgent,
            'processing_status' => CashfreeWebhookLog::STATUS_RECEIVED,
        ]);
    }

    private function postValidateLinked(
        Order $order,
        string $cfPaymentId,
        float $amount,
    ): LegacyCashfreeExistingOrderLinkOutcome {
        $deskCf = trim((string) ($order->cashfree_payment_id ?? ''));
        $deskAmount = round((float) ($order->payment_amount ?? 0), 2);

        if ($deskCf !== $cfPaymentId || abs($deskAmount - $amount) >= 0.01) {
            return new LegacyCashfreeExistingOrderLinkOutcome(
                success: false,
                alreadyLinked: false,
                reason: 'Post-link Desk payment field validation failed.',
            );
        }

        $journals = FinanceJournal::query()
            ->where('idempotency_key', 'order_payment:'.$order->id)
            ->get();

        if ($journals->count() > 1) {
            return new LegacyCashfreeExistingOrderLinkOutcome(
                success: false,
                alreadyLinked: false,
                reason: 'Duplicate finance journals for order_payment idempotency key.',
                details: ['journal_ids' => $journals->pluck('id')->all()],
            );
        }

        $journal = $journals->first();

        return new LegacyCashfreeExistingOrderLinkOutcome(
            success: true,
            alreadyLinked: false,
            reason: 'Linked and post-validation passed.',
            details: [
                'cf_payment_id' => $cfPaymentId,
                'amount' => $deskAmount,
                'currency' => 'INR',
                'payment_method' => $order->payment_method,
                'payment_date' => $order->payment_date?->toIso8601String(),
                'finance_journal_id' => $journal?->id,
                'idempotency_key' => 'order_payment:'.$order->id,
                'maximum_refundable' => $this->refundCalculationService->maximumRefundable($order),
            ],
        );
    }
}
