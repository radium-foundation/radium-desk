<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareFulfilmentOperationalStage;
use App\Enums\HardwareFulfilmentState;
use App\Enums\HardwareOperationsSection;
use App\Enums\HardwareWorkspaceFilter;
use App\Enums\HardwareWorkspaceScope;
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
    /**
     * @var array<string, Collection<int, HardwareFulfilmentOperationalRow>>
     */
    private array $allRowsCache = [];

    public int $lastInspectedFulfilmentCount = 0;

    public function __construct(
        private readonly HardwareAwaitingFulfilmentQueue $awaiting,
        private readonly HardwareShipmentEligibility $eligibility,
        private readonly HardwareFulfilmentOperationalClassifier $classifier,
        private readonly HardwareNeedsActionSqlQuery $needsActionSql,
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
     * Unfiltered Hardware workspace size for the dashboard chip.
     * Same dataset as the default rendered/selectable work queue (no search, no sub-queue).
     */
    /**
     * Unfiltered Hardware workspace size for the dashboard chip.
     * Active = fulfilments not in shipped/synced plus awaiting/RIN support rows.
     * Does not inspect shipment eligibility.
     */
    public function workspaceTotal(Carbon $fromIst, Carbon $toIst): int
    {
        return HardwareFulfilment::query()
            ->whereNotIn('state', [
                HardwareFulfilmentState::Shipped->value,
                HardwareFulfilmentState::Synced->value,
            ])
            ->count()
            + $this->awaiting->workCandidateCount($fromIst, $toIst)
            + $this->windowedRinOrderCount($fromIst, $toIst);
    }

    /**
     * Presentation queue for the Hardware dashboard. Read-only. No provider calls.
     *
     * @return array{
     *     rows: Collection<int, HardwareFulfilmentOperationalRow>,
     *     scope_counts: array<string, int>,
     *     filter_counts: array<string, int>,
     *     total: int,
     *     unfiltered_total: int
     * }
     */
    public function dashboard(
        Carbon $fromIst,
        Carbon $toIst,
        string $search = '',
        HardwareWorkspaceScope $scope = HardwareWorkspaceScope::Active,
        HardwareWorkspaceFilter $filter = HardwareWorkspaceFilter::NeedsAction,
    ): array {
        $this->lastInspectedFulfilmentCount = 0;
        $page = max(1, (int) request()->integer('hw_page', request()->integer('page', 1)));
        $perPage = 40;

        $loadAll = in_array($filter, [
            HardwareWorkspaceFilter::All,
            HardwareWorkspaceFilter::Ready,
            HardwareWorkspaceFilter::Exceptions,
            HardwareWorkspaceFilter::Pickup,
            HardwareWorkspaceFilter::Scheduled,
        ], true);

        $scopeCounts = [
            HardwareWorkspaceScope::Active->value => $this->workspaceTotal($fromIst, $toIst),
            HardwareWorkspaceScope::Shipped->value => HardwareFulfilment::query()
                ->whereIn('state', [
                    HardwareFulfilmentState::Shipped->value,
                    HardwareFulfilmentState::Synced->value,
                ])
                ->count(),
        ];

        if ($loadAll) {
            return $this->legacyDashboard($fromIst, $toIst, $search, $scope, $filter, $scopeCounts, $page, $perPage);
        }

        $filterCounts = $this->needsActionSql->filterCounts($fromIst, $toIst, $search);

        $searchAcrossActive = $search !== ''
            && $scope === HardwareWorkspaceScope::Active
            && ! $filter->isShipping()
            && ! in_array($filter, [
                HardwareWorkspaceFilter::Completed,
                HardwareWorkspaceFilter::Delivered,
            ], true);

        if ($searchAcrossActive) {
            $pageSet = $this->needsActionSql->searchActivePage($fromIst, $toIst, $search, $page, $perPage);
            $rows = $this->hydratePage($pageSet['fulfilment_ids'], $pageSet['order_ids']);

            return [
                'rows' => $rows,
                'scope_counts' => $scopeCounts,
                'filter_counts' => $filterCounts,
                'total' => $rows->count(),
                'unfiltered_total' => $pageSet['total'],
                'page' => $page,
                'per_page' => $perPage,
            ];
        }

        $pageSet = $this->needsActionSql->page($scope, $filter, $fromIst, $toIst, $search, $page, $perPage);
        $rows = $this->hydratePage($pageSet['fulfilment_ids'], $pageSet['order_ids']);

        return [
            'rows' => $rows,
            'scope_counts' => $scopeCounts,
            'filter_counts' => $filterCounts,
            'total' => $rows->count(),
            'unfiltered_total' => $pageSet['total'],
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param  array<string, int>  $scopeCounts
     * @return array{
     *     rows: Collection<int, HardwareFulfilmentOperationalRow>,
     *     scope_counts: array<string, int>,
     *     filter_counts: array<string, int>,
     *     total: int,
     *     unfiltered_total: int,
     *     page: int,
     *     per_page: int
     * }
     */
    private function legacyDashboard(
        Carbon $fromIst,
        Carbon $toIst,
        string $search,
        HardwareWorkspaceScope $scope,
        HardwareWorkspaceFilter $filter,
        array $scopeCounts,
        int $page,
        int $perPage,
    ): array {
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

        $activeRows = $rows->reject(
            static fn (HardwareFulfilmentOperationalRow $row): bool => $row->isShippedWorkspaceItem(),
        )->values();
        $shippedRows = $rows->filter(
            static fn (HardwareFulfilmentOperationalRow $row): bool => $row->isShippedWorkspaceItem(),
        )->values();

        $scopeCounts = [
            HardwareWorkspaceScope::Active->value => $activeRows->count(),
            HardwareWorkspaceScope::Shipped->value => $shippedRows->count(),
        ];

        $filterCounts = [];
        foreach (HardwareWorkspaceFilter::cases() as $workspaceFilter) {
            $filterCounts[$workspaceFilter->value] = $rows
                ->filter(static fn (HardwareFulfilmentOperationalRow $row): bool => $row->matchesWorkspaceFilter($workspaceFilter))
                ->count();
        }

        if ($scope === HardwareWorkspaceScope::Shipped) {
            $scopedRows = $rows->filter(
                static fn (HardwareFulfilmentOperationalRow $row): bool => $row->isCompletedPresentation(),
            )->values();
        } elseif ($search !== '' || $filter === HardwareWorkspaceFilter::All) {
            $scopedRows = $activeRows;
        } else {
            $scopedRows = $rows->filter(
                static fn (HardwareFulfilmentOperationalRow $row): bool => $row->matchesWorkspaceFilter($filter),
            )->values();
        }

        $total = $scopedRows->count();
        $paged = $scopedRows->slice(($page - 1) * $perPage, $perPage)->values();

        return [
            'rows' => $paged,
            'scope_counts' => $scopeCounts,
            'filter_counts' => $filterCounts,
            'total' => $paged->count(),
            'unfiltered_total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param  list<int>  $fulfilmentIds
     * @param  list<int>  $orderIds
     * @return Collection<int, HardwareFulfilmentOperationalRow>
     */
    private function hydratePage(array $fulfilmentIds, array $orderIds): Collection
    {
        $fulfilments = $this->inspectFulfilmentsById($fulfilmentIds);

        if ($orderIds === []) {
            return $fulfilments->sortByDesc(
                static fn (HardwareFulfilmentOperationalRow $row): string => $row->orderDateIst
            )->values();
        }

        $orders = Order::query()->whereIn('id', $orderIds)->get();
        $commerceByOrder = $this->commerceOrdersForSupport($orders);
        $rows = $fulfilments;

        foreach ($orders as $order) {
            $commerce = $commerceByOrder[(int) $order->id] ?? $commerceByOrder[(string) $order->order_id] ?? null;
            $row = str_starts_with(strtoupper((string) $order->order_id), 'RIN')
                ? $this->classifier->fromRin($order, $commerce)
                : $this->classifier->fromAwaiting($order, $commerce);
            $rows = $rows->push($row);
        }

        return $rows->sortByDesc(
            static fn (HardwareFulfilmentOperationalRow $row): string => $row->orderDateIst
        )->values();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, HardwareFulfilmentOperationalRow>
     */
    private function inspectFulfilmentsById(array $ids): Collection
    {
        $this->lastInspectedFulfilmentCount += count($ids);
        if ($ids === []) {
            return collect();
        }

        return HardwareFulfilment::query()
            ->with([
                'commerceOrder.items',
                'commerceOrder.statutoryInvoice',
                'statutoryInvoice',
                'supportOrder',
                'serials.inventorySerial.branch',
                'serials.inventorySerial.product.packaging',
                'fulfilmentBranch',
                'shipment',
                'packageEvidences',
            ])
            ->whereIn('id', $ids)
            ->orderByDesc('id')
            ->get()
            ->map(function (HardwareFulfilment $fulfilment) {
                return $this->classifier->fromFulfilment(
                    $fulfilment,
                    $this->eligibility->inspect($fulfilment),
                );
            });
    }

    private function windowedRinOrderCount(Carbon $fromIst, Carbon $toIst): int
    {
        $fromBound = HardwareFulfilmentEligibility::createdAtSqlBound($fromIst);
        $toBound = HardwareFulfilmentEligibility::createdAtSqlBound($toIst);

        return Order::query()
            ->where('order_id', 'like', 'RIN%')
            ->where('created_at', '>=', $fromBound)
            ->where('created_at', '<=', $toBound)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('hardware_fulfilments')
                    ->whereColumn('hardware_fulfilments.source_id', 'orders.order_id');
            })
            ->count();
    }

    /**
     * @return Collection<int, HardwareFulfilmentOperationalRow>
     */
    private function allRows(Carbon $fromIst, Carbon $toIst, string $orderSearch, string $payment): Collection
    {
        $cacheKey = $fromIst->toIso8601String().'|'.$toIst->toIso8601String().'|'.$orderSearch.'|'.$payment;

        return $this->allRowsCache[$cacheKey] ??= $this->buildAllRows($fromIst, $toIst, $orderSearch, $payment);
    }

    /**
     * @return Collection<int, HardwareFulfilmentOperationalRow>
     */
    private function buildAllRows(Carbon $fromIst, Carbon $toIst, string $orderSearch, string $payment): Collection
    {
        $this->lastInspectedFulfilmentCount = HardwareFulfilment::query()->count();

        $fulfilments = HardwareFulfilment::query()
            ->with([
                'commerceOrder.items',
                'commerceOrder.statutoryInvoice',
                'statutoryInvoice',
                'supportOrder',
                'serials.inventorySerial.branch',
                'serials.inventorySerial.product.packaging',
                'fulfilmentBranch',
                'shipment',
                'packageEvidences',
            ])
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

        return Order::query()
            ->where('order_id', 'like', 'RIN%')
            ->where('created_at', '>=', $fromBound)
            ->where('created_at', '<=', $toBound)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('hardware_fulfilments')
                    ->whereColumn('hardware_fulfilments.source_id', 'orders.order_id');
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
