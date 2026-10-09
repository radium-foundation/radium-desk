<?php

namespace App\CentralWallet\Application;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CashfreeHistoricalIdentityRepairRefundExposureClassifier
{
    /**
     * @param  Collection<int, object>  $refundsForOrder
     */
    public function classifyForOrder(Collection $refundsForOrder, array $ledgerRefsPresent): CashfreeHistoricalIdentityRepairRefundExposure
    {
        if ($refundsForOrder->isEmpty()) {
            return CashfreeHistoricalIdentityRepairRefundExposure::NoRefundFound;
        }

        $deskLedger = false;
        $spokeLikely = false;
        $needsReview = false;

        foreach ($refundsForOrder as $refund) {
            $refNo = (string) ($refund->reference_no ?? '');
            if ($refNo !== '' && isset($ledgerRefsPresent[$refNo])) {
                $deskLedger = true;
            }

            if ((string) ($refund->approved_refund_method ?? '') === 'wallet'
                && in_array((string) ($refund->status ?? ''), ['closed', 'completed'], true)) {
                $exec = trim((string) ($refund->execution_transaction_id ?? ''));
                if ($exec !== '' && ($refNo === '' || ! isset($ledgerRefsPresent[$refNo]))) {
                    $spokeLikely = true;
                }
            }

            if (! in_array((string) ($refund->status ?? ''), ['closed', 'completed', 'rejected', 'revoked'], true)) {
                $needsReview = true;
            }
        }

        if ($needsReview) {
            return CashfreeHistoricalIdentityRepairRefundExposure::RequiresReview;
        }

        if ($deskLedger && $spokeLikely) {
            return CashfreeHistoricalIdentityRepairRefundExposure::Both;
        }

        if ($deskLedger) {
            return CashfreeHistoricalIdentityRepairRefundExposure::DeskLedger;
        }

        if ($spokeLikely) {
            return CashfreeHistoricalIdentityRepairRefundExposure::SpokeWallet;
        }

        return CashfreeHistoricalIdentityRepairRefundExposure::Unknown;
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<int, CashfreeHistoricalIdentityRepairRefundExposure>
     */
    public function classifyForOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $refunds = DB::table('refund_requests')
            ->whereNull('deleted_at')
            ->whereIn('order_id', $orderIds)
            ->get(['order_id', 'reference_no', 'status', 'approved_refund_method', 'execution_transaction_id']);

        $refNos = $refunds->pluck('reference_no')->filter()->unique()->values()->all();
        $ledgerRefsPresent = [];
        if ($refNos !== []) {
            foreach (DB::table('central_wallet_ledger_entries')
                ->whereIn('business_reference', $refNos)
                ->pluck('business_reference') as $ref) {
                $ledgerRefsPresent[(string) $ref] = true;
            }
        }

        $byOrder = [];
        foreach ($refunds as $refund) {
            $byOrder[$refund->order_id][] = $refund;
        }

        $result = [];
        foreach ($orderIds as $orderId) {
            $collection = collect($byOrder[$orderId] ?? []);
            $result[$orderId] = $this->classifyForOrder($collection, $ledgerRefsPresent);
        }

        return $result;
    }
}
