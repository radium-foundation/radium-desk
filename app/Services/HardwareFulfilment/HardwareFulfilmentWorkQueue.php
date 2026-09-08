<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareFulfilmentOperationalStage;
use App\Models\HardwareFulfilment;
use App\Models\Order;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalClassifier;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalRow;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalSummary;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only operational queue. Does not ingest, allocate, invoice, or ship.
 */
final class HardwareFulfilmentWorkQueue
{
    public function __construct(
        private readonly HardwareAwaitingFulfilmentQueue $awaiting,
        private readonly HardwareShipmentEligibility $eligibility,
        private readonly HardwareFulfilmentOperationalClassifier $classifier,
    ) {}

    /**
     * @return LengthAwarePaginator<int, HardwareFulfilmentOperationalRow>
     */
    public function paginate(
        Carbon $fromIst,
        Carbon $toIst,
        string $stage = '',
        string $orderSearch = '',
        string $payment = '',
        int $perPage = 40,
    ): LengthAwarePaginator {
        $rows = $this->allRows($fromIst, $toIst, $orderSearch, $payment);
        if ($stage !== '') {
            $rows = $rows->filter(
                static fn (HardwareFulfilmentOperationalRow $row): bool => $row->stage->value === $stage
            )->values();
        }

        $page = max(1, (int) request()->integer('page', 1));
        $slice = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        return new Paginator($slice, $rows->count(), $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'query' => request()->query(),
        ]);
    }

    public function summary(Carbon $fromIst, Carbon $toIst): HardwareFulfilmentOperationalSummary
    {
        $rows = $this->allRows($fromIst, $toIst, '', '');
        $counts = [];
        foreach (HardwareFulfilmentOperationalStage::cases() as $stage) {
            $counts[$stage->value] = 0;
        }
        foreach ($rows as $row) {
            $counts[$row->stage->value]++;
        }

        $fromUtc = $fromIst->copy()->timezone('UTC');
        $toUtc = $toIst->copy()->timezone('UTC');
        $excluded = $this->awaiting->excludedFromWorkQueue($fromUtc, $toUtc);
        $blockedReview = $counts[HardwareFulfilmentOperationalStage::BlockedReview->value] ?? 0;

        return new HardwareFulfilmentOperationalSummary(
            fromIst: $fromIst->format('Y-m-d H:i'),
            toIst: $toIst->format('Y-m-d H:i'),
            qualifying: $rows->count() - $blockedReview,
            blockedReview: $blockedReview,
            excluded: $excluded['unpaid'] + $excluded['desk_completed'] + $excluded['rin'],
            excludedUnpaid: $excluded['unpaid'],
            excludedCompleted: $excluded['desk_completed'],
            excludedRin: $excluded['rin'],
            stageCounts: $counts,
        );
    }

    /**
     * @return Collection<int, HardwareFulfilmentOperationalRow>
     */
    private function allRows(Carbon $fromIst, Carbon $toIst, string $orderSearch, string $payment): Collection
    {
        $fromUtc = $fromIst->copy()->timezone('UTC');
        $toUtc = $toIst->copy()->timezone('UTC');

        $fulfilments = HardwareFulfilment::query()
            ->with(['commerceOrder.items', 'serials.inventorySerial', 'fulfilmentBranch', 'shipment', 'packageEvidences'])
            ->orderByDesc('id')
            ->get()
            ->map(function (HardwareFulfilment $fulfilment) {
                return $this->classifier->fromFulfilment(
                    $fulfilment,
                    $this->eligibility->inspect($fulfilment),
                );
            });

        $awaiting = $this->awaiting->workCandidateOrders($fromUtc, $toUtc)->map(
            fn (Order $order): HardwareFulfilmentOperationalRow => $this->classifier->fromAwaiting($order)
        );

        $rows = $fulfilments->concat($awaiting);
        if ($orderSearch !== '') {
            $needle = strtoupper($orderSearch);
            $rows = $rows->filter(
                static fn (HardwareFulfilmentOperationalRow $row): bool => str_contains(strtoupper($row->sourceId), $needle)
            );
        }
        if ($payment === 'paid') {
            $rows = $rows->filter(static fn (HardwareFulfilmentOperationalRow $row): bool => $row->payment === 'Paid');
        } elseif ($payment === 'unpaid') {
            $rows = $rows->filter(static fn (HardwareFulfilmentOperationalRow $row): bool => $row->payment !== 'Paid');
        }

        return $rows->sortByDesc(static fn (HardwareFulfilmentOperationalRow $row): string => $row->orderDateIst)
            ->values();
    }
}
