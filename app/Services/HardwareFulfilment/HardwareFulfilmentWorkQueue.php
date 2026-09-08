<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareDashboardQueue;
use App\Enums\HardwareFulfilmentOperationalStage;
use App\Enums\HardwareOperationsSection;
use App\Models\CommerceOrder;
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
        string $section = '',
    ): LengthAwarePaginator {
        $rows = $this->allRows($fromIst, $toIst, $orderSearch, $payment);
        if ($section !== '') {
            $rows = $rows->filter(
                static fn (HardwareFulfilmentOperationalRow $row): bool => $row->section->value === $section
            )->values();
        }
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
        $sectionCounts = [];
        foreach (HardwareOperationsSection::cases() as $section) {
            $sectionCounts[$section->value] = 0;
        }
        $rinVisible = 0;
        foreach ($rows as $row) {
            $counts[$row->stage->value]++;
            $sectionCounts[$row->section->value]++;
            if ($row->source === 'RIN') {
                $rinVisible++;
            }
        }

        $excluded = $this->awaiting->excludedFromWorkQueue($fromIst, $toIst);
        $blockedReview = $counts[HardwareFulfilmentOperationalStage::BlockedReview->value] ?? 0;

        return new HardwareFulfilmentOperationalSummary(
            fromIst: $fromIst->format('Y-m-d H:i'),
            toIst: $toIst->format('Y-m-d H:i'),
            qualifying: $rows->count() - $blockedReview,
            blockedReview: $blockedReview,
            excluded: $excluded['unpaid'] + $excluded['desk_completed'],
            excludedUnpaid: $excluded['unpaid'],
            excludedCompleted: $excluded['desk_completed'],
            excludedRin: $excluded['rin'],
            stageCounts: $counts,
            sectionCounts: $sectionCounts,
            rinVisible: $rinVisible,
        );
    }

    /**
     * Presentation queue for the Hardware dashboard. Read-only. No provider calls.
     *
     * @return array{rows: Collection<int, HardwareFulfilmentOperationalRow>, counts: array<string, int>, total: int}
     */
    public function dashboard(Carbon $fromIst, Carbon $toIst, string $search = '', string $queue = ''): array
    {
        $rows = $this->allRows($fromIst, $toIst, '', '');
        if ($search !== '') {
            $needle = strtoupper($search);
            $rows = $rows->filter(function (HardwareFulfilmentOperationalRow $row) use ($needle): bool {
                return str_contains(strtoupper($row->sourceId), $needle)
                    || str_contains(strtoupper($row->customer), $needle)
                    || str_contains(strtoupper($row->serialStatus), $needle)
                    || str_contains(strtoupper($row->product), $needle);
            })->values();
        }

        $counts = [];
        foreach (HardwareDashboardQueue::cases() as $dashboardQueue) {
            $counts[$dashboardQueue->value] = 0;
        }
        foreach ($rows as $row) {
            $counts[$row->dashboardQueue()->value]++;
        }

        if ($queue !== '') {
            $rows = $rows->filter(
                static fn (HardwareFulfilmentOperationalRow $row): bool => $row->dashboardQueue()->value === $queue
            )->values();
        }

        return [
            'rows' => $rows,
            'counts' => $counts,
            'total' => $rows->count(),
        ];
    }

    /**
     * @return Collection<int, HardwareFulfilmentOperationalRow>
     */
    private function allRows(Carbon $fromIst, Carbon $toIst, string $orderSearch, string $payment): Collection
    {
        $fulfilments = HardwareFulfilment::query()
            ->with(['commerceOrder.items', 'supportOrder', 'serials.inventorySerial', 'fulfilmentBranch', 'shipment', 'packageEvidences'])
            ->orderByDesc('id')
            ->get()
            ->map(function (HardwareFulfilment $fulfilment) {
                return $this->classifier->fromFulfilment(
                    $fulfilment,
                    $this->eligibility->inspect($fulfilment),
                );
            });

        $awaitingOrders = $this->awaiting->workCandidateOrders($fromIst, $toIst);
        $rinOrders = $this->windowedRinOrders($fromIst, $toIst);
        $commerceByOrder = $this->commerceOrdersForSupport($awaitingOrders->concat($rinOrders));

        $awaiting = $awaitingOrders->map(
            fn (Order $order): HardwareFulfilmentOperationalRow => $this->classifier->fromAwaiting(
                $order,
                $commerceByOrder[(int) $order->id] ?? $commerceByOrder[(string) $order->order_id] ?? null,
            )
        );

        $rin = $rinOrders->map(
            fn (Order $order): HardwareFulfilmentOperationalRow => $this->classifier->fromRin(
                $order,
                $commerceByOrder[(int) $order->id] ?? $commerceByOrder[(string) $order->order_id] ?? null,
            )
        );

        $rows = $fulfilments->concat($awaiting)->concat($rin);
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

    /**
     * Desk-resident RIN support orders in the IST window. Display only.
     *
     * @return Collection<int, Order>
     */
    private function windowedRinOrders(Carbon $fromIst, Carbon $toIst): Collection
    {
        $fromBound = HardwareFulfilmentEligibility::createdAtSqlBound($fromIst);
        $toBound = HardwareFulfilmentEligibility::createdAtSqlBound($toIst);
        $sourceIds = HardwareFulfilment::query()->pluck('source_id')->filter()->all();

        return Order::query()
            ->where('order_id', 'like', 'RIN%')
            ->where('created_at', '>=', $fromBound)
            ->where('created_at', '<=', $toBound)
            ->when($sourceIds !== [], static function ($query) use ($sourceIds): void {
                $query->whereNotIn('order_id', $sourceIds);
            })
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return array<int|string, CommerceOrder>
     */
    private function commerceOrdersForSupport(Collection $orders): array
    {
        $ids = $orders->pluck('id')->filter()->unique()->values()->all();
        $sourceIds = $orders->pluck('order_id')->filter()->unique()->values()->all();
        if ($ids === [] && $sourceIds === []) {
            return [];
        }

        $map = [];
        CommerceOrder::query()
            ->with('items')
            ->where(function ($query) use ($ids, $sourceIds): void {
                if ($ids !== []) {
                    $query->whereIn('support_order_id', $ids);
                }
                if ($sourceIds !== []) {
                    $query->orWhereIn('source_id', $sourceIds);
                }
            })
            ->get()
            ->each(function (CommerceOrder $commerce) use (&$map): void {
                if ($commerce->support_order_id !== null) {
                    $map[(int) $commerce->support_order_id] = $commerce;
                }
                if (filled($commerce->source_id)) {
                    $map[(string) $commerce->source_id] = $commerce;
                }
            });

        return $map;
    }
}
