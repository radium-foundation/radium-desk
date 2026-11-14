<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Support\BusinessOrderId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only monitor for paid RadiumBox RBP product orders with commerce but no hardware fulfilment.
 * Does not mutate production data.
 */
final class RbpHardwareFulfilmentGapMonitor
{
    /**
     * @return list<array{
     *   source_id: string,
     *   commerce_order_id: int,
     *   commerce_order_no: string,
     *   commerce_created_at: string,
     *   reason: string
     * }>
     */
    public function findGaps(?int $limit = null): array
    {
        if (! config('hardware_fulfilment.rbp_gap_monitor.enabled', true)) {
            return [];
        }

        $graceMinutes = max(1, (int) config('hardware_fulfilment.rbp_gap_monitor.grace_minutes', 30));
        $cutoff = Carbon::now()->subMinutes($graceMinutes);

        $query = CommerceOrder::query()
            ->with('items')
            ->where('channel', StatutoryInvoiceChannel::RadiumBoxCom->value)
            ->whereRaw('UPPER(payment_status) = ?', ['PAID'])
            ->whereDoesntHave('hardwareFulfilment')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        /** @var Collection<int, CommerceOrder> $orders */
        $orders = $query->get();

        $gaps = [];
        foreach ($orders as $order) {
            $sourceId = strtoupper(trim((string) $order->source_id));
            $parsed = BusinessOrderId::parse($sourceId);
            if ($parsed === null || $parsed['prefix'] !== 'RBP' || $parsed['hardware'] !== true) {
                continue;
            }

            if (HardwareFulfilmentEligibility::isFrozenSourceId($sourceId)) {
                continue;
            }

            if (HardwareFulfilmentEligibility::isHoldSourceId($sourceId)
                || HardwareFulfilmentEligibility::metadataShowsHold($order->metadata)) {
                continue;
            }

            if (HardwareFulfilmentEligibility::isBlockedUntilAuthorized($sourceId)) {
                continue;
            }

            if (HardwareFulfilmentEligibility::commerceOrderVoidsFulfilment($order)) {
                continue;
            }

            if (! HardwareFulfilmentEligibility::hasHardwareLines($order)) {
                continue;
            }

            if (HardwareFulfilmentEligibility::isFrozenForFulfilment($sourceId, $order)) {
                continue;
            }

            $gaps[] = [
                'source_id' => $sourceId,
                'commerce_order_id' => (int) $order->id,
                'commerce_order_no' => (string) $order->order_no,
                'commerce_created_at' => $order->created_at?->toIso8601String() ?? '',
                'reason' => 'paid_physical_rbp_commerce_without_hardware_fulfilment',
            ];
        }

        return $gaps;
    }

    /**
     * @return list<array{source_id: string, commerce_order_id: int, hardware_fulfilment_count: int}>
     */
    public function findDuplicateFulfilmentCorrelationWarnings(?int $limit = 50): array
    {
        $rows = CommerceOrder::query()
            ->select('source_id')
            ->selectRaw('COUNT(*) as commerce_count')
            ->where('channel', StatutoryInvoiceChannel::RadiumBoxCom->value)
            ->whereRaw('UPPER(source_id) LIKE ?', ['RBP%'])
            ->groupBy('source_id')
            ->having('commerce_count', '>', 1)
            ->limit($limit)
            ->get();

        $warnings = [];
        foreach ($rows as $row) {
            $warnings[] = [
                'source_id' => strtoupper((string) $row->source_id),
                'commerce_order_id' => 0,
                'hardware_fulfilment_count' => (int) $row->commerce_count,
            ];
        }

        return $warnings;
    }
}