<?php

namespace App\ReadModels\Integrations;

use App\Data\CashfreeFailedWebhookClassificationReport;
use App\Data\Integrations\CashfreeIntegrityMetricsV1;
use App\Services\Cashfree\CashfreePaymentIntegrityService;

/**
 * Read-only projection over CashfreePaymentIntegrityService.
 *
 * - No SQL, business rules, repair, or retry logic.
 * - No cache layer (owner + OperationsCashfreeHealthService retain existing caches).
 * - Methods pure-delegate to the owner so consumer call sequences stay identical.
 */
class CashfreeIntegrityReadModel
{
    public function __construct(
        private readonly CashfreePaymentIntegrityService $integrityService,
    ) {}

    /**
     * Stable integrity KPI projection.
     *
     * Call sequence: classifyFailedWebhooks → paidWithoutDeskOrderCount.
     * Alert is derived from those loaded counts (no second hydrate/classify).
     */
    public function metrics(): CashfreeIntegrityMetricsV1
    {
        $classification = $this->integrityService->classifyFailedWebhooks();
        $paidWithoutDeskOrderCount = $this->integrityService->paidWithoutDeskOrderCount();
        $requiresAlert = $this->integrityService->requiresCashfreeHealthAlertFromCounts(
            $paidWithoutDeskOrderCount,
            $classification->activeFailedWebhooks,
        );

        return new CashfreeIntegrityMetricsV1(
            paidWithoutDeskOrderCount: $paidWithoutDeskOrderCount,
            activeFailedWebhooks: $classification->activeFailedWebhooks,
            historicalResolvedFailures: $classification->historicalResolvedFailures,
            invalidEventFailures: $classification->invalidEventFailures,
            totalFailedWebhooks: $classification->totalFailed,
            countsByCategory: $classification->countsByCategory,
            oldestFailedAt: $classification->oldestFailedAt,
            newestFailedAt: $classification->newestFailedAt,
            affectedOrderIds: $classification->affectedOrderIds,
            requiresAlert: $requiresAlert,
        );
    }

    public function paidWithoutDeskOrderCount(): int
    {
        return $this->integrityService->paidWithoutDeskOrderCount();
    }

    /**
     * @return array{count: int, order_ids: list<string>}
     */
    public function missingPaidOrderSample(int $limit = 5): array
    {
        return $this->integrityService->missingPaidOrderSample($limit);
    }

    public function activeFailedWebhookCount(): int
    {
        return $this->integrityService->activeFailedWebhookCount();
    }

    public function historicalResolvedFailureCount(): int
    {
        return $this->integrityService->historicalResolvedFailureCount();
    }

    public function classifyFailedWebhooks(): CashfreeFailedWebhookClassificationReport
    {
        return $this->integrityService->classifyFailedWebhooks();
    }

    public function requiresCashfreeHealthAlert(): bool
    {
        return $this->integrityService->requiresCashfreeHealthAlert();
    }

    /**
     * Same alert semantics as requiresCashfreeHealthAlert() without re-hydrate.
     */
    public function requiresCashfreeHealthAlertFromCounts(
        int $paidWithoutDeskOrderCount,
        int $activeFailedWebhooks,
    ): bool {
        return $this->integrityService->requiresCashfreeHealthAlertFromCounts(
            $paidWithoutDeskOrderCount,
            $activeFailedWebhooks,
        );
    }
}
