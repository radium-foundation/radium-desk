<?php

namespace App\Services\Cashfree;

use App\Data\Cashfree\LegacyCashfreePaymentDiscoveryReport;
use App\Data\Cashfree\LegacyCashfreePaymentDiscoveryRow;
use App\Models\Order;
use App\Services\Cashfree\Exceptions\CashfreeApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Read-only discovery: Desk incomplete payment authority vs Cashfree PG (GET only).
 *
 * Idempotent future execution design (not implemented here):
 * - Gate on explicit approved desk order id list + cf_payment_id
 * - Reuse CashfreeWebhookProcessorService::process() after factory-built payload
 * - Rely on applyCashfreePaymentFieldsToExistingOrder + order_payment:{id} journal idempotency
 * - Never call CashfreeMissedWebhookHealService for existing unpaid legacy rows (desk_order_exists skip)
 */
final class LegacyCashfreePaymentDiscoveryService
{
    public function __construct(
        private readonly CashfreeApiClient $apiClient,
        private readonly LegacyCashfreePaymentDiscoveryClassifier $classifier,
    ) {}

    /**
     * @param  list<string>|null  $businessOrderIds
     */
    public function discover(
        int $limit = 25,
        bool $legacyOnly = true,
        ?array $businessOrderIds = null,
    ): LegacyCashfreePaymentDiscoveryReport {
        $limit = max(1, min($limit, 500));
        $this->apiClient->assertConfigured();

        $candidates = $this->candidateQuery($legacyOnly, $businessOrderIds)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $rows = [];

        foreach ($candidates as $order) {
            $rows[] = $this->discoverOne($order);
        }

        return new LegacyCashfreePaymentDiscoveryReport(
            dryRun: true,
            candidateLimit: $limit,
            legacyOnly: $legacyOnly,
            rows: $rows,
        );
    }

    public function discoverOne(Order $order): LegacyCashfreePaymentDiscoveryRow
    {
        $businessOrderId = trim((string) $order->order_id);

        $cashfreeOrder = null;
        $payments = [];
        $refunds = [];
        $apiError = null;

        $refundsLookupFailed = false;

        try {
            $cashfreeOrder = $this->apiClient->getOrder($businessOrderId);
            $payments = $this->apiClient->getOrderPayments($businessOrderId);
        } catch (CashfreeApiException $exception) {
            $apiError = $exception;
        }

        if ($apiError === null) {
            try {
                $refunds = $this->apiClient->getOrderRefunds($businessOrderId);
            } catch (CashfreeApiException) {
                $refunds = [];
                $refundsLookupFailed = true;
            }
        }

        $assessment = $this->classifier->classify(
            order: $order,
            cashfreeOrder: $cashfreeOrder,
            payments: $payments,
            refunds: $refunds,
            apiError: $apiError,
            refundsLookupFailed: $refundsLookupFailed,
        );

        $legacyLabel = $order->isLegacyImported()
            ? 'legacy ('.($order->legacy_source ?? 'unknown').')'
            : 'native';

        $orderDate = $order->legacy_order_date
            ?? $order->created_at;

        $orderTotal = is_array($cashfreeOrder)
            ? $this->formatDecimal($cashfreeOrder['order_amount'] ?? null)
            : null;

        return new LegacyCashfreePaymentDiscoveryRow(
            deskOrderId: (int) $order->id,
            deskOrderNumber: $businessOrderId,
            legacyNativeClassification: $legacyLabel,
            orderDate: $orderDate instanceof Carbon ? $orderDate->toIso8601String() : (string) $orderDate,
            orderTotal: $orderTotal,
            deskPaymentAmount: $this->formatDecimal($order->payment_amount),
            deskPaymentMethod: $order->payment_method,
            deskPaymentDate: $order->payment_date?->toDateString(),
            deskCashfreePaymentId: $order->cashfree_payment_id,
            cashfreeOrderStatus: $assessment['cashfree_order_status'],
            cashfreeCfOrderId: $assessment['cashfree_cf_order_id'],
            cashfreePaymentIds: $assessment['cashfree_payment_ids'],
            cashfreeAmount: $assessment['cashfree_amount'],
            cashfreePaymentStatus: $assessment['cashfree_payment_status'],
            cashfreePaymentGroup: $assessment['cashfree_payment_group'],
            cashfreePaymentDate: $assessment['cashfree_payment_date'],
            cashfreeRefundStatus: $assessment['cashfree_refund_status'],
            customerCorrelation: $assessment['customer_correlation'],
            classification: $assessment['classification'],
            reason: $assessment['reason'],
            recommendedNextAction: $assessment['recommended_next_action'],
        );
    }

    /**
     * @param  list<string>|null  $businessOrderIds
     */
    private function candidateQuery(bool $legacyOnly, ?array $businessOrderIds): Builder
    {
        $query = Order::query()
            ->whereNotNull('order_id')
            ->where('order_id', '!=', '')
            ->where(function (Builder $builder): void {
                $builder->whereNull('payment_amount')
                    ->orWhere('payment_amount', '<=', 0);
            })
            ->where(function (Builder $builder): void {
                $builder->whereNull('cashfree_payment_id')
                    ->orWhere('cashfree_payment_id', '=', '');
            });

        if ($legacyOnly) {
            $query->whereNotNull('legacy_source')->where('legacy_source', '!=', '');
        }

        if ($businessOrderIds !== null && $businessOrderIds !== []) {
            $normalized = array_values(array_unique(array_map(
                static fn (string $id): string => trim($id),
                $businessOrderIds,
            )));

            $query->whereIn('order_id', $normalized);
        }

        return $query;
    }

    private function formatDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
