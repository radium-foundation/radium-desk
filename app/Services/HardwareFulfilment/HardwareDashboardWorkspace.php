<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareWorkspaceFilter;
use App\Enums\HardwareWorkspaceScope;
use App\Models\HardwareFulfilment;
use App\Models\Incident;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalRow;
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
    ) {}

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
     *     unfilteredTotal: int
     * }
     */
    public function present(Request $request): array
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
            'scopes' => HardwareWorkspaceScope::cases(),
            'filters' => HardwareWorkspaceFilter::cases(),
            'needs_action_filters' => HardwareWorkspaceFilter::needsActionFilters(),
            'shipping_filters' => HardwareWorkspaceFilter::shippingFilters(),
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
     * Hardware workspace chip. Counts the same default work-queue dataset the
     * operator can see/select — not every open RDE/RIN service case.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    public function overlayFilterCounts(array $counts): array
    {
        $counts['hardware'] = $this->chipCount();

        return $counts;
    }

    public function chipCount(): int
    {
        $timezone = HardwareFulfilmentEligibility::CUTOFF_TIMEZONE;

        return $this->workQueue->workspaceTotal(
            HardwareFulfilmentEligibility::cutoffInstant(),
            Carbon::now($timezone),
        );
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
