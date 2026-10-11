<?php

namespace App\Services\Cashfree;

use App\Data\Cashfree\LegacyCashfreeExistingOrderLinkMetadata;
use App\Data\Cashfree\LegacyCashfreePaymentDiscoveryRow;
use App\Enums\LegacyCashfreePaymentDiscoveryClassification;
use App\Enums\LegacyCashfreePaymentReconciliationState;
use App\Models\LegacyCashfreePaymentReconciliation;
use App\Models\Order;
use App\Services\Cashfree\Exceptions\CashfreeApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

final class LegacyCashfreePaymentReconciliationService
{
    public const PROMPT_ID = 'RadiumDesk-P-04-10-198';

    public const INGEST_SOURCE = 'legacy_import_payment_reconciliation';

    public const USER_AGENT = 'desk/legacy-cashfree-payment-reconciliation';

    public function __construct(
        private readonly LegacyCashfreePaymentDiscoveryService $discoveryService,
        private readonly LegacyCashfreeExistingOrderPaymentLinker $linker,
        private readonly CashfreeApiClient $apiClient,
    ) {}

    public function enqueueForImportedLegacyOrder(Order $order): ?LegacyCashfreePaymentReconciliation
    {
        if (! $this->enabled()) {
            return null;
        }

        if (! $order->isLegacyImported()) {
            return null;
        }

        if ($this->linker->orderHasAuthoritativePayment($order)) {
            return null;
        }

        return LegacyCashfreePaymentReconciliation::query()->firstOrCreate(
            ['order_id' => $order->id],
            [
                'business_order_id' => (string) $order->order_id,
                'legacy_source' => $order->legacy_source,
                'state' => LegacyCashfreePaymentReconciliationState::Pending,
                'reason' => 'Legacy import created; awaiting Cashfree payment verification.',
            ],
        );
    }

    /**
     * @return Builder<LegacyCashfreePaymentReconciliation>
     */
    public function dueReconciliationsQuery(): Builder
    {
        return LegacyCashfreePaymentReconciliation::query()
            ->where(function (Builder $query): void {
                $query->where('state', LegacyCashfreePaymentReconciliationState::Pending)
                    ->orWhere('state', LegacyCashfreePaymentReconciliationState::FailedRetryable)
                    ->orWhere('state', LegacyCashfreePaymentReconciliationState::RefundStateUnknown);
            })
            ->where(function (Builder $query): void {
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id');
    }

    public function process(
        LegacyCashfreePaymentReconciliation $reconciliation,
        bool $dryRun = true,
    ): LegacyCashfreePaymentReconciliation {
        if (! $this->enabled()) {
            return $reconciliation;
        }

        $order = $reconciliation->order;

        if ($order === null) {
            $reconciliation->update([
                'state' => LegacyCashfreePaymentReconciliationState::FailedReviewRequired,
                'reason' => 'Desk order missing for reconciliation row.',
            ]);

            return $reconciliation->fresh() ?? $reconciliation;
        }

        if (! $order->isLegacyImported()) {
            $reconciliation->update([
                'state' => LegacyCashfreePaymentReconciliationState::FailedReviewRequired,
                'reason' => 'Not a legacy-imported order; reconciliation skipped.',
            ]);

            return $reconciliation->fresh() ?? $reconciliation;
        }

        if ($this->linker->orderHasAuthoritativePayment($order)) {
            return $this->markAlreadyLinked($reconciliation, $order, 'Desk payment authority present before reconciliation run.');
        }

        $reconciliation->update([
            'state' => LegacyCashfreePaymentReconciliationState::Verifying,
            'attempt_count' => $reconciliation->attempt_count + 1,
            'last_attempt_at' => now(),
        ]);

        try {
            $row = $this->discoveryService->discoverOne($order->fresh() ?? $order);
        } catch (Throwable $exception) {
            return $this->markRetryable($reconciliation, 'Discovery failed: '.$exception->getMessage());
        }

        $mapped = $this->mapDiscoveryRow($row);

        $audit = $this->auditMetaFromRow($row);

        if ($mapped['state'] === LegacyCashfreePaymentReconciliationState::VerifiedPayment) {
            if ($dryRun) {
                $reconciliation->update([
                    'state' => LegacyCashfreePaymentReconciliationState::VerifiedPayment,
                    'discovery_classification' => $row->classification->value,
                    'reason' => $row->reason,
                    'audit_meta' => $audit,
                    'next_attempt_at' => null,
                ]);

                return $reconciliation->fresh() ?? $reconciliation;
            }

            return $this->executeLink($reconciliation, $order, $row, $audit);
        }

        $reconciliation->update([
            'state' => $mapped['state'],
            'discovery_classification' => $row->classification->value,
            'reason' => $row->reason,
            'audit_meta' => $audit,
            'next_attempt_at' => $mapped['next_attempt_at'],
        ]);

        return $reconciliation->fresh() ?? $reconciliation;
    }

    /**
     * @return array{state: LegacyCashfreePaymentReconciliationState, next_attempt_at: ?Carbon}
     */
    private function mapDiscoveryRow(LegacyCashfreePaymentDiscoveryRow $row): array
    {
        if ($row->cashfreeRefundStatus === 'refunds_lookup_failed') {
            return [
                'state' => LegacyCashfreePaymentReconciliationState::RefundStateUnknown,
                'next_attempt_at' => $this->nextBackoffAttempt(),
            ];
        }

        return match ($row->classification) {
            LegacyCashfreePaymentDiscoveryClassification::SafeToLink => [
                'state' => LegacyCashfreePaymentReconciliationState::VerifiedPayment,
                'next_attempt_at' => null,
            ],
            LegacyCashfreePaymentDiscoveryClassification::AlreadyLinked => [
                'state' => LegacyCashfreePaymentReconciliationState::AlreadyLinked,
                'next_attempt_at' => null,
            ],
            LegacyCashfreePaymentDiscoveryClassification::NoCashfreePayment => [
                'state' => $this->isRetryableApiFailureReason($row->reason)
                    ? LegacyCashfreePaymentReconciliationState::FailedRetryable
                    : LegacyCashfreePaymentReconciliationState::NoCashfreePayment,
                'next_attempt_at' => $this->isRetryableApiFailureReason($row->reason)
                    ? $this->nextBackoffAttempt()
                    : null,
            ],
            LegacyCashfreePaymentDiscoveryClassification::AmountMismatch => [
                'state' => LegacyCashfreePaymentReconciliationState::AmountMismatch,
                'next_attempt_at' => null,
            ],
            LegacyCashfreePaymentDiscoveryClassification::CustomerMismatch => [
                'state' => LegacyCashfreePaymentReconciliationState::CustomerMismatch,
                'next_attempt_at' => null,
            ],
            LegacyCashfreePaymentDiscoveryClassification::ConflictingDeskPayment => [
                'state' => LegacyCashfreePaymentReconciliationState::ConflictingDeskPayment,
                'next_attempt_at' => null,
            ],
            LegacyCashfreePaymentDiscoveryClassification::RefundConflict => [
                'state' => LegacyCashfreePaymentReconciliationState::FailedReviewRequired,
                'next_attempt_at' => null,
            ],
            LegacyCashfreePaymentDiscoveryClassification::MultiplePayments,
            LegacyCashfreePaymentDiscoveryClassification::Ambiguous => [
                'state' => LegacyCashfreePaymentReconciliationState::Ambiguous,
                'next_attempt_at' => null,
            ],
        };
    }

    private function isRetryableApiFailureReason(string $reason): bool
    {
        return str_contains($reason, 'HTTP 429')
            || str_contains($reason, 'HTTP 5')
            || str_contains($reason, 'timeout')
            || str_contains($reason, 'Connection');
    }

    /**
     * @param  array<string, mixed>  $audit
     */
    private function executeLink(
        LegacyCashfreePaymentReconciliation $reconciliation,
        Order $order,
        LegacyCashfreePaymentDiscoveryRow $row,
        array $audit,
    ): LegacyCashfreePaymentReconciliation {
        $businessOrderId = (string) $order->order_id;
        $cfPaymentId = trim((string) ($row->cashfreePaymentIds ?? ''));

        if ($cfPaymentId === '') {
            $reconciliation->update([
                'state' => LegacyCashfreePaymentReconciliationState::FailedReviewRequired,
                'reason' => 'Verified row missing cf_payment_id.',
                'audit_meta' => $audit,
            ]);

            return $reconciliation->fresh() ?? $reconciliation;
        }

        try {
            $cashfreeOrder = $this->apiClient->getOrder($businessOrderId);
            $payments = $this->apiClient->getOrderPayments($businessOrderId);
            $this->linker->getOrderRefundsWithRetry($businessOrderId);
        } catch (CashfreeApiException $exception) {
            return $this->markRetryable($reconciliation, 'Cashfree fetch before link failed: '.$exception->getMessage(), $audit);
        }

        $paymentEntity = $this->linker->findSuccessPayment($payments, $cfPaymentId);

        if ($paymentEntity === null) {
            $reconciliation->update([
                'state' => LegacyCashfreePaymentReconciliationState::FailedReviewRequired,
                'reason' => 'SUCCESS payment entity not found at link time.',
                'audit_meta' => $audit,
            ]);

            return $reconciliation->fresh() ?? $reconciliation;
        }

        $outcome = $this->linker->linkVerifiedSuccessPayment(
            order: $order,
            cashfreeOrder: $cashfreeOrder,
            paymentEntity: $paymentEntity,
            metadata: new LegacyCashfreeExistingOrderLinkMetadata(
                promptId: self::PROMPT_ID,
                ingestSource: self::INGEST_SOURCE,
                userAgent: self::USER_AGENT,
            ),
        );

        if ($outcome->alreadyLinked) {
            return $this->markAlreadyLinked($reconciliation, $order->fresh() ?? $order, $outcome->reason, $audit, $outcome->details);
        }

        if (! $outcome->success) {
            $reconciliation->update([
                'state' => LegacyCashfreePaymentReconciliationState::FailedReviewRequired,
                'reason' => $outcome->reason,
                'audit_meta' => array_merge($audit, ['link_details' => $outcome->details]),
            ]);

            return $reconciliation->fresh() ?? $reconciliation;
        }

        $reconciliation->update([
            'state' => LegacyCashfreePaymentReconciliationState::AlreadyLinked,
            'discovery_classification' => $row->classification->value,
            'reason' => $outcome->reason,
            'cf_payment_id' => $cfPaymentId,
            'finance_journal_id' => $outcome->details['finance_journal_id'] ?? null,
            'audit_meta' => array_merge($audit, ['link_details' => $outcome->details]),
            'next_attempt_at' => null,
        ]);

        return $reconciliation->fresh() ?? $reconciliation;
    }

    /**
     * @param  array<string, mixed>  $audit
     * @param  array<string, mixed>  $details
     */
    private function markAlreadyLinked(
        LegacyCashfreePaymentReconciliation $reconciliation,
        Order $order,
        string $reason,
        array $audit = [],
        array $details = [],
    ): LegacyCashfreePaymentReconciliation {
        $reconciliation->update([
            'state' => LegacyCashfreePaymentReconciliationState::AlreadyLinked,
            'reason' => $reason,
            'cf_payment_id' => $order->cashfree_payment_id,
            'finance_journal_id' => $details['finance_journal_id'] ?? null,
            'audit_meta' => $audit !== [] ? $audit : $reconciliation->audit_meta,
            'next_attempt_at' => null,
        ]);

        return $reconciliation->fresh() ?? $reconciliation;
    }

    /**
     * @param  array<string, mixed>  $audit
     */
    private function markRetryable(
        LegacyCashfreePaymentReconciliation $reconciliation,
        string $reason,
        array $audit = [],
    ): LegacyCashfreePaymentReconciliation {
        $maxAttempts = max(1, (int) config('cashfree.legacy_payment_reconciliation.max_attempts', 12));

        $state = $reconciliation->attempt_count >= $maxAttempts
            ? LegacyCashfreePaymentReconciliationState::FailedReviewRequired
            : LegacyCashfreePaymentReconciliationState::FailedRetryable;

        $reconciliation->update([
            'state' => $state,
            'reason' => $reason,
            'audit_meta' => $audit !== [] ? $audit : $reconciliation->audit_meta,
            'next_attempt_at' => $state === LegacyCashfreePaymentReconciliationState::FailedRetryable
                ? $this->nextBackoffAttempt($reconciliation->attempt_count)
                : null,
        ]);

        return $reconciliation->fresh() ?? $reconciliation;
    }

    private function nextBackoffAttempt(int $attemptCount = 1): Carbon
    {
        $baseMinutes = max(1, (int) config('cashfree.legacy_payment_reconciliation.retry_base_minutes', 15));
        $minutes = min(360, $baseMinutes * (2 ** min(6, max(0, $attemptCount - 1))));

        return now()->addMinutes($minutes);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditMetaFromRow(LegacyCashfreePaymentDiscoveryRow $row): array
    {
        return [
            'cashfree_order_status' => $row->cashfreeOrderStatus,
            'cashfree_payment_ids' => $row->cashfreePaymentIds,
            'cashfree_amount' => $row->cashfreeAmount,
            'cashfree_payment_status' => $row->cashfreePaymentStatus,
            'cashfree_refund_status' => $row->cashfreeRefundStatus,
            'customer_correlation' => $row->customerCorrelation,
            'classification' => $row->classification->value,
            'attempt_recorded_at' => now()->toIso8601String(),
        ];
    }

    public function enabled(): bool
    {
        return filter_var(
            config('cashfree.legacy_payment_reconciliation.enabled', false),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    public function executionEnabled(): bool
    {
        return filter_var(
            config('cashfree.legacy_payment_reconciliation.execution_enabled', false),
            FILTER_VALIDATE_BOOLEAN,
        );
    }
}
