<?php

namespace App\CentralWallet\Application;

/**
 * @phpstan-type OrderRow array{
 *     id: int,
 *     order_id: string,
 *     customer_id: ?string,
 *     customer_email: ?string,
 *     normalized_email: string,
 *     subject_hash: ?string,
 * }
 */
final class CashfreeHistoricalIdentityRepairCohortPlan
{
    /**
     * @param  list<OrderRow>  $orders
     * @param  array<int, CashfreeHistoricalIdentityRepairRefundExposure>  $refundFlagsByOrderId
     */
    public function __construct(
        public readonly string $cohortKey,
        public readonly ?string $normalizedEmail,
        public readonly ?string $subjectHash,
        public readonly CashfreeHistoricalIdentityRepairIdentityClass $identityClass,
        public readonly array $orders,
        public readonly ?string $targetDeskCustomerId,
        public readonly ?string $targetCentralWalletId,
        public readonly bool $requiresNewCustomer,
        public readonly bool $requiresNewWallet,
        public readonly array $refundFlagsByOrderId,
    ) {}

    public function orderCount(): int
    {
        return count($this->orders);
    }

    /**
     * @return list<int>
     */
    public function orderPrimaryKeys(): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $this->orders);
    }

    public function worstRefundExposure(): CashfreeHistoricalIdentityRepairRefundExposure
    {
        $priority = [
            CashfreeHistoricalIdentityRepairRefundExposure::Both,
            CashfreeHistoricalIdentityRepairRefundExposure::DeskLedger,
            CashfreeHistoricalIdentityRepairRefundExposure::SpokeWallet,
            CashfreeHistoricalIdentityRepairRefundExposure::RequiresReview,
            CashfreeHistoricalIdentityRepairRefundExposure::Unknown,
            CashfreeHistoricalIdentityRepairRefundExposure::NoRefundFound,
        ];

        $found = CashfreeHistoricalIdentityRepairRefundExposure::NoRefundFound;
        foreach ($this->refundFlagsByOrderId as $flag) {
            if (array_search($flag, $priority, true) < array_search($found, $priority, true)) {
                $found = $flag;
            }
        }

        return $found;
    }
}
