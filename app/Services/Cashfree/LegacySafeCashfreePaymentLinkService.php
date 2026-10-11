<?php

namespace App\Services\Cashfree;

use App\Data\Cashfree\LegacySafeCashfreePaymentLinkReport;
use App\Data\Cashfree\LegacySafeCashfreePaymentLinkRowResult;
use App\Enums\LegacyCashfreePaymentDiscoveryClassification;
use App\Enums\LegacySafeCashfreePaymentLinkResult;
use App\Models\CashfreeWebhookLog;
use App\Models\FinanceJournal;
use App\Models\Order;
use App\Services\Cashfree\Exceptions\CashfreeApiException;
use App\Services\RefundCalculationService;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Gated execution: link ONLY desk orders classified SAFE_TO_LINK in an approved discovery JSON report.
 *
 * Uses CashfreeWebhookPayloadFactory → CashfreeWebhookLog → CashfreeWebhookProcessorService::process()
 * (P-04-10-194 pattern). Never uses CashfreeMissedWebhookHealService (desk_order_exists skip).
 */
final class LegacySafeCashfreePaymentLinkService
{
    public const PROMPT_ID = 'RadiumDesk-P-04-10-197';

    public const INGEST_SOURCE = 'legacy_safe_payment_link';

    public const USER_AGENT = 'desk/legacy-safe-cashfree-payment-link';

    public function __construct(
        private readonly CashfreeApiClient $apiClient,
        private readonly CashfreeWebhookPayloadFactory $payloadFactory,
        private readonly CashfreeWebhookProcessorService $webhookProcessorService,
        private readonly CashfreeWebhookPayloadParser $payloadParser,
        private readonly LegacyCashfreePaymentDiscoveryClassifier $classifier,
        private readonly RefundCalculationService $refundCalculationService,
    ) {}

    /**
     * @param  list<string>|null  $onlyBusinessOrderIds
     */
    public function linkFromDiscoveryReport(
        string $reportPath,
        bool $dryRun = true,
        ?array $onlyBusinessOrderIds = null,
    ): LegacySafeCashfreePaymentLinkReport {
        $this->apiClient->assertConfigured();

        $eligible = $this->loadEligibleSafeRows($reportPath);

        if ($onlyBusinessOrderIds !== null && $onlyBusinessOrderIds !== []) {
            $filter = array_map(static fn (string $id): string => strtoupper(trim($id)), $onlyBusinessOrderIds);
            $eligible = array_values(array_filter(
                $eligible,
                static fn (array $row): bool => in_array(strtoupper((string) $row['desk_order_number']), $filter, true),
            ));
        }

        $results = [];

        foreach ($eligible as $index => $evidence) {
            try {
                $results[] = $this->linkOne($evidence, $dryRun);
            } catch (RuntimeException $exception) {
                if (str_contains($exception->getMessage(), 'Systemic Cashfree')) {
                    throw $exception;
                }

                $results[] = new LegacySafeCashfreePaymentLinkRowResult(
                    deskOrderId: (int) $evidence['desk_order_id'],
                    deskOrderNumber: (string) $evidence['desk_order_number'],
                    result: LegacySafeCashfreePaymentLinkResult::FailedMutation,
                    reason: $exception->getMessage(),
                );
            }

            if ($index < count($eligible) - 1) {
                usleep(1_500_000);
            }
        }

        return new LegacySafeCashfreePaymentLinkReport(
            dryRun: $dryRun,
            sourceReportPath: $reportPath,
            eligibleCount: count($eligible),
            rows: $results,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadEligibleSafeRows(string $reportPath): array
    {
        $absolute = $this->resolveReportPath($reportPath);

        if (! File::isFile($absolute)) {
            throw new InvalidArgumentException('Discovery report not found: '.$absolute);
        }

        $decoded = json_decode(File::get($absolute), true);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Discovery report is not valid JSON.');
        }

        $rows = $decoded['rows'] ?? null;

        if (! is_array($rows)) {
            throw new InvalidArgumentException('Discovery report is missing rows.');
        }

        $safe = [];
        $deskIds = [];
        $orderNumbers = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('Invalid row in discovery report.');
            }

            $classification = (string) ($row['classification'] ?? '');

            if ($classification !== LegacyCashfreePaymentDiscoveryClassification::SafeToLink->value) {
                continue;
            }

            $deskId = (int) ($row['desk_order_id'] ?? 0);
            $orderNumber = trim((string) ($row['desk_order_number'] ?? ''));

            if ($deskId <= 0 || $orderNumber === '') {
                throw new InvalidArgumentException('SAFE_TO_LINK row missing desk_order_id or desk_order_number.');
            }

            if (isset($deskIds[$deskId])) {
                throw new InvalidArgumentException('Duplicate desk_order_id in SAFE_TO_LINK set: '.$deskId);
            }

            if (isset($orderNumbers[strtoupper($orderNumber)])) {
                throw new InvalidArgumentException('Duplicate desk_order_number in SAFE_TO_LINK set: '.$orderNumber);
            }

            $deskIds[$deskId] = true;
            $orderNumbers[strtoupper($orderNumber)] = true;
            $safe[] = $row;
        }

        if (count($safe) !== 84) {
            throw new InvalidArgumentException(
                'Expected exactly 84 SAFE_TO_LINK records in report; found '.count($safe).'.',
            );
        }

        usort($safe, static fn (array $a, array $b): int => ((int) $a['desk_order_id']) <=> ((int) $b['desk_order_id']));

        return $safe;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function linkOne(array $evidence, bool $dryRun): LegacySafeCashfreePaymentLinkRowResult
    {
        $deskOrderId = (int) $evidence['desk_order_id'];
        $orderNumber = (string) $evidence['desk_order_number'];
        $expectedPaymentId = trim((string) ($evidence['cashfree_payment_ids'] ?? ''));
        $expectedAmount = $this->decimal($evidence['cashfree_amount'] ?? null);

        if ($expectedPaymentId === '' || $expectedAmount === null) {
            return new LegacySafeCashfreePaymentLinkRowResult(
                deskOrderId: $deskOrderId,
                deskOrderNumber: $orderNumber,
                result: LegacySafeCashfreePaymentLinkResult::SkippedRevalidationFailure,
                reason: 'Discovery evidence missing cashfree_payment_ids or cashfree_amount.',
            );
        }

        $order = Order::query()->find($deskOrderId);

        if ($order === null) {
            return new LegacySafeCashfreePaymentLinkRowResult(
                deskOrderId: $deskOrderId,
                deskOrderNumber: $orderNumber,
                result: LegacySafeCashfreePaymentLinkResult::FailedMutation,
                reason: 'Desk order not found.',
            );
        }

        if (strtoupper(trim((string) $order->order_id)) !== strtoupper($orderNumber)) {
            return new LegacySafeCashfreePaymentLinkRowResult(
                deskOrderId: $deskOrderId,
                deskOrderNumber: $orderNumber,
                result: LegacySafeCashfreePaymentLinkResult::SkippedRevalidationFailure,
                reason: 'Desk order_id does not match discovery desk_order_number.',
            );
        }

        $prelinked = $this->assessAlreadyLinked($order, $expectedPaymentId, $expectedAmount);

        if ($prelinked !== null) {
            return $prelinked;
        }

        if ($this->hasConflictingDeskPayment($order, $expectedPaymentId)) {
            return new LegacySafeCashfreePaymentLinkRowResult(
                deskOrderId: $deskOrderId,
                deskOrderNumber: $orderNumber,
                result: LegacySafeCashfreePaymentLinkResult::SkippedRevalidationFailure,
                reason: 'Desk already has conflicting payment authority.',
            );
        }

        $cashfreeOrder = null;
        $payments = [];
        $refunds = [];
        $apiError = null;
        $refundsLookupFailed = false;

        try {
            $cashfreeOrder = $this->apiClient->getOrder($orderNumber);
            $payments = $this->apiClient->getOrderPayments($orderNumber);
        } catch (CashfreeApiException $exception) {
            $apiError = $exception;
        }

        if ($apiError === null) {
            try {
                $refunds = $this->getOrderRefundsWithRetry($orderNumber);
            } catch (CashfreeApiException $exception) {
                $refunds = [];
                $refundsLookupFailed = true;

                if ($this->isNonRetryableRefundsFailure($exception)) {
                    throw new RuntimeException(
                        'Systemic Cashfree refunds lookup failure: '.$exception->getMessage(),
                    );
                }
            }
        }

        $classified = $this->classifier->classify(
            order: $order,
            cashfreeOrder: $cashfreeOrder,
            payments: $payments,
            refunds: $refunds,
            apiError: $apiError,
            refundsLookupFailed: $refundsLookupFailed,
        );

        if ($classified['classification'] !== LegacyCashfreePaymentDiscoveryClassification::SafeToLink) {
            return new LegacySafeCashfreePaymentLinkRowResult(
                deskOrderId: $deskOrderId,
                deskOrderNumber: $orderNumber,
                result: LegacySafeCashfreePaymentLinkResult::SkippedRevalidationFailure,
                reason: 'Revalidation classification '.$classified['classification']->value.': '.$classified['reason'],
            );
        }

        $livePaymentId = trim((string) ($classified['cashfree_payment_ids'] ?? ''));
        $liveAmount = $this->decimal($classified['cashfree_amount'] ?? null);

        if ($livePaymentId !== $expectedPaymentId) {
            return new LegacySafeCashfreePaymentLinkRowResult(
                deskOrderId: $deskOrderId,
                deskOrderNumber: $orderNumber,
                result: LegacySafeCashfreePaymentLinkResult::SkippedRevalidationFailure,
                reason: 'Cashfree payment ID changed since discovery.',
                details: ['expected' => $expectedPaymentId, 'live' => $livePaymentId],
            );
        }

        if ($liveAmount === null || abs($liveAmount - $expectedAmount) >= 0.01) {
            return new LegacySafeCashfreePaymentLinkRowResult(
                deskOrderId: $deskOrderId,
                deskOrderNumber: $orderNumber,
                result: LegacySafeCashfreePaymentLinkResult::SkippedRevalidationFailure,
                reason: 'Cashfree amount changed since discovery.',
                details: ['expected' => $expectedAmount, 'live' => $liveAmount],
            );
        }

        $paymentEntity = $this->findSuccessPayment($payments, $expectedPaymentId);

        if ($paymentEntity === null) {
            return new LegacySafeCashfreePaymentLinkRowResult(
                deskOrderId: $deskOrderId,
                deskOrderNumber: $orderNumber,
                result: LegacySafeCashfreePaymentLinkResult::SkippedRevalidationFailure,
                reason: 'Could not resolve SUCCESS payment entity for factory payload.',
            );
        }

        if ($dryRun) {
            return new LegacySafeCashfreePaymentLinkRowResult(
                deskOrderId: $deskOrderId,
                deskOrderNumber: $orderNumber,
                result: LegacySafeCashfreePaymentLinkResult::LinkedSuccessfully,
                reason: 'Dry-run: revalidation passed; would link via CashfreeWebhookProcessorService.',
                details: [
                    'cf_payment_id' => $expectedPaymentId,
                    'amount' => $expectedAmount,
                    'currency' => 'INR',
                ],
            );
        }

        try {
            $payload = $this->payloadFactory->fromOrderAndSuccessPayment($cashfreeOrder ?? [], $paymentEntity);
            $log = $this->resolveWebhookLog($expectedPaymentId, $payload);
            $processed = $this->webhookProcessorService->process($log->fresh() ?? $log);

            if ($processed->processing_status !== CashfreeWebhookProcessorService::STATUS_PROCESSED) {
                return new LegacySafeCashfreePaymentLinkRowResult(
                    deskOrderId: $deskOrderId,
                    deskOrderNumber: $orderNumber,
                    result: LegacySafeCashfreePaymentLinkResult::FailedMutation,
                    reason: 'Webhook processor did not mark log processed: '.($processed->processing_error ?? 'unknown'),
                    details: ['webhook_log_id' => $processed->id],
                );
            }
        } catch (Throwable $exception) {
            return new LegacySafeCashfreePaymentLinkRowResult(
                deskOrderId: $deskOrderId,
                deskOrderNumber: $orderNumber,
                result: LegacySafeCashfreePaymentLinkResult::FailedMutation,
                reason: $exception->getMessage(),
            );
        }

        return $this->postValidateLinked($order->fresh() ?? $order, $expectedPaymentId, $expectedAmount);
    }

    private function assessAlreadyLinked(
        Order $order,
        string $expectedPaymentId,
        float $expectedAmount,
    ): ?LegacySafeCashfreePaymentLinkRowResult {
        $deskCf = trim((string) ($order->cashfree_payment_id ?? ''));
        $deskAmount = $this->decimal($order->payment_amount);

        if ($deskCf === '' && ($deskAmount === null || $deskAmount <= 0)) {
            return null;
        }

        if ($deskCf !== $expectedPaymentId) {
            return null;
        }

        if ($deskAmount === null || abs($deskAmount - $expectedAmount) >= 0.01) {
            return new LegacySafeCashfreePaymentLinkRowResult(
                deskOrderId: $order->id,
                deskOrderNumber: (string) $order->order_id,
                result: LegacySafeCashfreePaymentLinkResult::SkippedRevalidationFailure,
                reason: 'Desk cashfree_payment_id matches but payment_amount differs from discovery.',
            );
        }

        $journal = FinanceJournal::query()
            ->where('idempotency_key', 'order_payment:'.$order->id)
            ->first();

        $maxRefundable = $this->refundCalculationService->maximumRefundable($order);

        return new LegacySafeCashfreePaymentLinkRowResult(
            deskOrderId: $order->id,
            deskOrderNumber: (string) $order->order_id,
            result: LegacySafeCashfreePaymentLinkResult::AlreadyLinked,
            reason: 'Desk payment and Cashfree payment id already satisfied.',
            details: [
                'finance_journal_id' => $journal?->id,
                'idempotency_key' => 'order_payment:'.$order->id,
                'maximum_refundable' => $maxRefundable,
            ],
        );
    }

    private function hasConflictingDeskPayment(Order $order, string $expectedPaymentId): bool
    {
        $deskCf = trim((string) ($order->cashfree_payment_id ?? ''));
        $deskAmount = $this->decimal($order->payment_amount);

        if ($deskCf !== '' && $deskCf !== $expectedPaymentId) {
            return true;
        }

        if ($deskAmount !== null && $deskAmount > 0 && $deskCf === '') {
            return true;
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     * @return array<string, mixed>|null
     */
    private function findSuccessPayment(array $payments, string $cfPaymentId): ?array
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveWebhookLog(string $cfPaymentId, array $payload): CashfreeWebhookLog
    {
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
            'request_headers' => $this->syntheticHeaders(),
            'request_payload' => $payload,
            'received_at' => now(),
            'source_ip' => '127.0.0.1',
            'user_agent' => self::USER_AGENT,
            'processing_status' => CashfreeWebhookLog::STATUS_RECEIVED,
        ]);
    }

    private function postValidateLinked(
        Order $order,
        string $expectedPaymentId,
        float $expectedAmount,
    ): LegacySafeCashfreePaymentLinkRowResult {
        $deskCf = trim((string) ($order->cashfree_payment_id ?? ''));
        $deskAmount = $this->decimal($order->payment_amount);

        if ($deskCf !== $expectedPaymentId) {
            return new LegacySafeCashfreePaymentLinkRowResult(
                deskOrderId: $order->id,
                deskOrderNumber: (string) $order->order_id,
                result: LegacySafeCashfreePaymentLinkResult::FailedPostValidation,
                reason: 'Post-link cashfree_payment_id mismatch.',
            );
        }

        if ($deskAmount === null || abs($deskAmount - $expectedAmount) >= 0.01) {
            return new LegacySafeCashfreePaymentLinkRowResult(
                deskOrderId: $order->id,
                deskOrderNumber: (string) $order->order_id,
                result: LegacySafeCashfreePaymentLinkResult::FailedPostValidation,
                reason: 'Post-link payment_amount mismatch.',
            );
        }

        $journals = FinanceJournal::query()
            ->where('idempotency_key', 'order_payment:'.$order->id)
            ->get();

        if ($journals->count() > 1) {
            return new LegacySafeCashfreePaymentLinkRowResult(
                deskOrderId: $order->id,
                deskOrderNumber: (string) $order->order_id,
                result: LegacySafeCashfreePaymentLinkResult::FailedPostValidation,
                reason: 'Duplicate finance journals for order_payment idempotency key.',
                details: ['journal_ids' => $journals->pluck('id')->all()],
            );
        }

        $journal = $journals->first();
        $maxRefundable = $this->refundCalculationService->maximumRefundable($order);

        return new LegacySafeCashfreePaymentLinkRowResult(
            deskOrderId: $order->id,
            deskOrderNumber: (string) $order->order_id,
            result: LegacySafeCashfreePaymentLinkResult::LinkedSuccessfully,
            reason: 'Linked and post-validation passed.',
            details: [
                'cf_payment_id' => $expectedPaymentId,
                'amount' => $deskAmount,
                'currency' => 'INR',
                'payment_method' => $order->payment_method,
                'payment_date' => $order->payment_date?->toIso8601String(),
                'finance_journal_id' => $journal?->id,
                'idempotency_key' => 'order_payment:'.$order->id,
                'maximum_refundable' => $maxRefundable,
                'wallet_effect' => 'none_observed_in_desk_row',
            ],
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function syntheticHeaders(): array
    {
        return [
            'X-Desk-Ingest-Source' => [self::INGEST_SOURCE],
            'X-Desk-Prompt-Id' => [self::PROMPT_ID],
            'User-Agent' => [self::USER_AGENT],
        ];
    }

    private function resolveReportPath(string $reportPath): string
    {
        if ($reportPath !== '' && $reportPath[0] === '/') {
            return $reportPath;
        }

        return base_path($reportPath);
    }

    private function decimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getOrderRefundsWithRetry(string $orderNumber): array
    {
        $attempts = 0;
        $maxAttempts = 6;

        while (true) {
            $attempts++;

            try {
                return $this->apiClient->getOrderRefunds($orderNumber);
            } catch (CashfreeApiException $exception) {
                if ($attempts >= $maxAttempts || ! $this->isRateLimited($exception)) {
                    throw $exception;
                }

                usleep((int) (500_000 * (2 ** ($attempts - 1))));
            }
        }
    }

    private function isRateLimited(CashfreeApiException $exception): bool
    {
        return str_contains($exception->getMessage(), 'HTTP 429');
    }

    private function isNonRetryableRefundsFailure(CashfreeApiException $exception): bool
    {
        $message = $exception->getMessage();

        if ($this->isRateLimited($exception)) {
            return false;
        }

        return str_contains($message, 'HTTP 401')
            || str_contains($message, 'HTTP 403');
    }
}
