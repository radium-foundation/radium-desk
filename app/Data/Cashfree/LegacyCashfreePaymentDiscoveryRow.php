<?php

namespace App\Data\Cashfree;

use App\Enums\LegacyCashfreePaymentDiscoveryClassification;

final class LegacyCashfreePaymentDiscoveryRow
{
    public function __construct(
        public readonly int $deskOrderId,
        public readonly string $deskOrderNumber,
        public readonly string $legacyNativeClassification,
        public readonly ?string $orderDate,
        public readonly ?string $orderTotal,
        public readonly ?string $deskPaymentAmount,
        public readonly ?string $deskPaymentMethod,
        public readonly ?string $deskPaymentDate,
        public readonly ?string $deskCashfreePaymentId,
        public readonly ?string $cashfreeOrderStatus,
        public readonly ?string $cashfreeCfOrderId,
        public readonly ?string $cashfreePaymentIds,
        public readonly ?string $cashfreeAmount,
        public readonly ?string $cashfreePaymentStatus,
        public readonly ?string $cashfreePaymentGroup,
        public readonly ?string $cashfreePaymentDate,
        public readonly ?string $cashfreeRefundStatus,
        public readonly string $customerCorrelation,
        public readonly LegacyCashfreePaymentDiscoveryClassification $classification,
        public readonly string $reason,
        public readonly string $recommendedNextAction,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'desk_order_id' => $this->deskOrderId,
            'desk_order_number' => $this->deskOrderNumber,
            'legacy_native_classification' => $this->legacyNativeClassification,
            'order_date' => $this->orderDate,
            'order_total' => $this->orderTotal,
            'desk_payment_amount' => $this->deskPaymentAmount,
            'desk_payment_method' => $this->deskPaymentMethod,
            'desk_payment_date' => $this->deskPaymentDate,
            'desk_cashfree_payment_id' => $this->deskCashfreePaymentId,
            'cashfree_order_status' => $this->cashfreeOrderStatus,
            'cashfree_cf_order_id' => $this->cashfreeCfOrderId,
            'cashfree_payment_ids' => $this->cashfreePaymentIds,
            'cashfree_amount' => $this->cashfreeAmount,
            'cashfree_payment_status' => $this->cashfreePaymentStatus,
            'cashfree_payment_group' => $this->cashfreePaymentGroup,
            'cashfree_payment_date' => $this->cashfreePaymentDate,
            'cashfree_refund_status' => $this->cashfreeRefundStatus,
            'customer_correlation' => $this->customerCorrelation,
            'classification' => $this->classification->value,
            'reason' => $this->reason,
            'recommended_next_action' => $this->recommendedNextAction,
        ];
    }
}
