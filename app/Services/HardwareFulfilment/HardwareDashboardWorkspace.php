<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareWorkspaceFilter;
use App\Enums\HardwareWorkspaceScope;
use App\Models\HardwareFulfilment;
use App\Models\Incident;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalRow;
use App\Services\Shipping\Data\ShiprocketWalletBalancePresentation;
use App\Services\Shipping\ShiprocketWalletBalanceReadService;
use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use App\Support\HardwareFulfilment\HardwareFulfilmentNavigation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only Hardware dashboard presenter. Does not ingest, ship, or call providers.
 */
final class HardwareDashboardWorkspace
{
    public function __construct(
        private readonly HardwareFulfilmentWorkQueue $workQueue,
        private readonly ShiprocketWalletBalanceReadService $walletBalance,
        private readonly HardwareDashboardCountsCache $countsCache,
    ) {}

    /**
     * Fast shell metadata for async hardware dashboard loads.
     *
     * @return array<string, mixed>
     */
    public function shell(Request $request): array
    {
        $search = trim((string) $request->query('q', ''));
        $scope = $this->resolveScope($request);
        $filter = $this->resolveFilter($request, $scope);
        $page = max(1, (int) $request->integer('hw_page', $request->integer('page', 1)));
        $perPage = 40;

        return [
            'defer_rows' => true,
            'rows' => collect(),
            'scope_counts' => [],
            'filter_counts' => [],
            'counts_pending' => true,
            'scope' => $scope->value,
            'filter' => $filter->value,
            'search' => $search,
            'scopes' => HardwareWorkspaceScope::cases(),
            'filters' => HardwareWorkspaceFilter::cases(),
            'needs_action_filters' => HardwareWorkspaceFilter::needsActionFilters(),
            'shipping_filters' => HardwareWorkspaceFilter::shippingFilters(),
            'incidentIds' => [],
            'operableFulfilmentIds' => [],
            'canOperateHardware' => HardwareFulfilmentAccess::allows($request->user()),
            'shiprocketBalance' => $this->walletBalance->presentFromCacheOnly($request->user()),
            'shiprocket_balance_pending' => $this->walletBalance->canView($request->user()),
            'unfilteredTotal' => null,
            'page' => $page,
            'per_page' => $perPage,
            'inspected_fulfilment_count' => 0,
        ];
    }

    /**
     * Hydrated rows and counts for the async hardware rows API.
     *
     * @return array{
     *     rows: Collection<int, HardwareFulfilmentOperationalRow>,
     *     scope_counts: array<string, int>,
     *     filter_counts: array<string, int>,
     *     scope: string,
     *     filter: string,
     *     search: string,
     *     incidentIds: array<int, int>,
     *     operableFulfilmentIds: array<int, true>,
     *     canOperateHardware: bool,
     *     unfilteredTotal: int,
     *     page: int,
     *     per_page: int,
     *     inspected_fulfilment_count: int
     * }
     */
    public function rowsPayload(Request $request): array
    {
        $search = trim((string) $request->query('q', ''));
        $scope = $this->resolveScope($request);
        $filter = $this->resolveFilter($request, $scope);

        $range = $this->range($request);
        $dashboard = $this->workQueue->dashboard($range['from'], $range['to'], $search, $scope, $filter);

        return [
            'rows' => $dashboard['rows'],
            'scope_counts' => $dashboard['scope_counts'],
            'filter_counts' => $dashboard['filter_counts'],
            'scope' => $scope->value,
            'filter' => $filter->value,
            'search' => $search,
            'incidentIds' => $this->incidentIds($dashboard['rows']),
            'operableFulfilmentIds' => $this->operableFulfilmentIds($dashboard['rows'], $request->user()),
            'canOperateHardware' => HardwareFulfilmentAccess::allows($request->user()),
            'unfilteredTotal' => $dashboard['unfiltered_total'],
            'page' => $dashboard['page'] ?? 1,
            'per_page' => $dashboard['per_page'] ?? 40,
            'inspected_fulfilment_count' => $this->workQueue->lastInspectedFulfilmentCount,
        ];
    }

    /**
     * @return array{
     *     rows: Collection<int, HardwareFulfilmentOperationalRow>,
     *     scope_counts: array<string, int>,
     *     filter_counts: array<string, int>,
     *     scope: string,
     *     filter: string,
     *     search: string,
     *     scopes: list<HardwareWorkspaceScope>,
     *     filters: list<HardwareWorkspaceFilter>,
     *     incidentIds: array<int, int>,
     *     operableFulfilmentIds: array<int, true>,
     *     canOperateHardware: bool,
     *     shiprocketBalance: ?ShiprocketWalletBalancePresentation,
     *     unfilteredTotal: int
     * }
     */
    public function present(Request $request): array
    {
        return [
            ...$this->rowsPayload($request),
            'scopes' => HardwareWorkspaceScope::cases(),
            'filters' => HardwareWorkspaceFilter::cases(),
            'needs_action_filters' => HardwareWorkspaceFilter::needsActionFilters(),
            'shipping_filters' => HardwareWorkspaceFilter::shippingFilters(),
            'shiprocketBalance' => $this->walletBalance->presentFor($request->user()),
            'defer_rows' => false,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    public function overlayFilterCounts(array $counts): array
    {
        if ($counts === []) {
            return $counts;
        }

        $counts['hardware'] = $this->chipCount();

        return $counts;
    }

    public function chipCount(): int
    {
        $timezone = HardwareFulfilmentEligibility::CUTOFF_TIMEZONE;
        $from = HardwareFulfilmentEligibility::cutoffInstant();
        $to = Carbon::now($timezone);

        return $this->countsCache->scopeCounts($from, $to)['active'];
    }

    /**
     * Admin dashboard stuck-hardware indicator. Reuses Needs Action SQL classifier counts.
     *
     * @return array{
     *     needs_action_total: int,
     *     breakdown: list<array{filter: string, label: string, count: int, tone: string}>
     * }
     */
    public function stuckOrderSummary(): array
    {
        $timezone = HardwareFulfilmentEligibility::CUTOFF_TIMEZONE;
        $from = HardwareFulfilmentEligibility::cutoffInstant();
        $to = Carbon::now($timezone);
        $filterCounts = $this->countsCache->filterCounts($from, $to, '');

        $breakdown = [];
        foreach (HardwareWorkspaceFilter::needsActionFilters() as $filter) {
            $count = (int) ($filterCounts[$filter->value] ?? 0);
            if ($count <= 0) {
                continue;
            }

            $breakdown[] = [
                'filter' => $filter->value,
                'label' => $filter->label(),
                'count' => $count,
                'tone' => $filter->tone(),
            ];
        }

        return [
            'needs_action_total' => (int) ($filterCounts[HardwareWorkspaceFilter::NeedsAction->value] ?? 0),
            'breakdown' => $breakdown,
        ];
    }

    public function resolveScope(Request $request): HardwareWorkspaceScope
    {
        $legacyQueue = trim((string) $request->query('hw_queue', ''));
        if ($legacyQueue === 'completed') {
            return HardwareWorkspaceScope::Shipped;
        }

        $scope = HardwareWorkspaceScope::tryFrom(trim((string) $request->query('hw_scope', '')));

        return $scope ?? HardwareWorkspaceScope::Active;
    }

    public function resolveFilter(Request $request, HardwareWorkspaceScope $scope): HardwareWorkspaceFilter
    {
        if ($scope === HardwareWorkspaceScope::Shipped) {
            return HardwareWorkspaceFilter::Completed;
        }

        $legacyQueue = trim((string) $request->query('hw_queue', ''));
        $rawFilter = trim((string) $request->query('hw_filter', ''));
        $filter = HardwareWorkspaceFilter::tryFrom($rawFilter);
        if ($filter !== null) {
            return $filter;
        }

        if ($legacyQueue !== '' && $legacyQueue !== 'completed') {
            $legacyFilter = HardwareWorkspaceFilter::tryFrom($legacyQueue);
            if ($legacyFilter !== null) {
                return $legacyFilter;
            }
        }

        return HardwareWorkspaceFilter::NeedsAction;
    }

    /**
     * @return array{from: Carbon, to: Carbon}
     */
    public function range(Request $request): array
    {
        $timezone = HardwareFulfilmentEligibility::CUTOFF_TIMEZONE;
        $from = $request->filled('from')
            ? Carbon::parse((string) $request->query('from'), $timezone)->startOfDay()
            : HardwareFulfilmentEligibility::cutoffInstant();
        $to = $request->filled('to')
            ? Carbon::parse((string) $request->query('to'), $timezone)->endOfDay()
            : Carbon::now($timezone);
        $now = Carbon::now($timezone);
        if ($to->gt($now)) {
            $to = $now;
        }
        if ($from->gt($to)) {
            $from = $to->copy()->startOfDay();
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * @param  Collection<int, HardwareFulfilmentOperationalRow>  $rows
     * @return array<int, int>
     */
    private function incidentIds(Collection $rows): array
    {
        $orderIds = $rows->pluck('supportOrderId')->filter()->unique()->values()->all();
        if ($orderIds === []) {
            return [];
        }

        return Incident::query()
            ->selectRaw('MAX(id) as id, order_id')
            ->whereIn('order_id', $orderIds)
            ->groupBy('order_id')
            ->pluck('id', 'order_id')
            ->all();
    }

    /**
     * @param  Collection<int, HardwareFulfilmentOperationalRow>  $rows
     * @return array<int, true>
     */
    private function operableFulfilmentIds(Collection $rows, mixed $user): array
    {
        $ids = $rows->pluck('fulfilmentId')->filter()->unique()->values()->all();
        if ($ids === [] || $user === null || ! HardwareFulfilmentAccess::allows($user)) {
            return [];
        }

        $allowed = [];
        HardwareFulfilment::query()
            ->whereIn('id', $ids)
            ->get()
            ->each(function (HardwareFulfilment $fulfilment) use ($user, &$allowed): void {
                if (HardwareFulfilmentNavigation::userCanOpen($user, $fulfilment)) {
                    $allowed[(int) $fulfilment->id] = true;
                }
            });

        return $allowed;
    }
}
