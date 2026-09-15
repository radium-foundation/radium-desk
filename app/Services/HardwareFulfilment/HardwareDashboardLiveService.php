<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareWorkspaceFilter;
use App\Enums\HardwareWorkspaceScope;
use App\Models\HardwareFulfilment;
use App\Models\Incident;
use App\Models\User;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalClassifier;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalRow;
use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use App\Support\HardwareFulfilment\HardwareFulfilmentNavigation;
use Illuminate\Support\Carbon;

final class HardwareDashboardLiveService
{
    public function __construct(
        private readonly HardwareFulfilmentWorkQueue $workQueue,
        private readonly HardwareShipmentEligibility $eligibility,
        private readonly HardwareFulfilmentOperationalClassifier $classifier,
    ) {}

    /**
     * @param  list<int>  $fulfilmentIds
     * @return array{
     *     rows: list<array{
     *         fulfilment_id: int,
     *         source_id: string,
     *         incident_id: int|null,
     *         queue: string,
     *         scope: string,
     *         filter: string,
     *         status: string,
     *         next_action: string,
     *         html: string
     *     }>,
     *     remove_fulfilment_ids: list<int>,
     *     scope_counts: array<string, int>,
     *     filter_counts: array<string, int>,
     *     hardware_count: int
     * }
     */
    public function livePayload(
        User $user,
        array $fulfilmentIds,
        HardwareWorkspaceScope $scope,
        HardwareWorkspaceFilter $filter,
        string $search = '',
        ?Carbon $from = null,
        ?Carbon $to = null,
    ): array {
        $from ??= HardwareFulfilmentEligibility::cutoffInstant();
        $to ??= Carbon::now(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);

        $dashboard = $this->workQueue->dashboard($from, $to, $search, $scope, $filter);
        $hardwareCount = $this->workQueue->workspaceTotal($from, $to);

        $wanted = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $fulfilmentIds),
            static fn (int $id): bool => $id > 0,
        )));

        $rows = [];
        $remove = [];
        $operable = $this->workspaceOperableMap($user, $wanted);
        $incidentIds = $this->incidentIdsForFulfilments($wanted);

        foreach ($wanted as $id) {
            $fulfilment = HardwareFulfilment::query()
                ->with(['commerceOrder.items', 'supportOrder', 'serials.inventorySerial', 'fulfilmentBranch', 'shipment', 'packageEvidences'])
                ->find($id);

            if ($fulfilment === null) {
                $remove[] = $id;

                continue;
            }

            $row = $this->classifier->fromFulfilment($fulfilment, $this->eligibility->inspect($fulfilment));

            if (! $this->rowMatchesView($row, $scope, $filter)) {
                $remove[] = $id;

                continue;
            }

            $incidentId = $fulfilment->support_order_id !== null
                ? ($incidentIds[(int) $fulfilment->support_order_id] ?? null)
                : null;

            $rows[] = [
                'fulfilment_id' => $id,
                'source_id' => $row->sourceId,
                'incident_id' => $incidentId !== null ? (int) $incidentId : null,
                'queue' => $row->dashboardQueue()->value,
                'scope' => $row->isShippedWorkspaceItem()
                    ? HardwareWorkspaceScope::Shipped->value
                    : HardwareWorkspaceScope::Active->value,
                'filter' => $this->rowFilterValue($row),
                'status' => $row->operatorStatus(),
                'next_action' => $row->nextAction,
                'html' => view('dashboard.partials.hardware-workspace-row', [
                    'row' => $row,
                    'incidentId' => $incidentId !== null ? (int) $incidentId : null,
                    'operableFulfilmentIds' => $operable,
                    'canOperateHardware' => HardwareFulfilmentAccess::allows($user),
                    'isShippedScope' => $scope === HardwareWorkspaceScope::Shipped,
                ])->render(),
            ];
        }

        return [
            'rows' => $rows,
            'remove_fulfilment_ids' => $remove,
            'scope_counts' => $dashboard['scope_counts'],
            'filter_counts' => $dashboard['filter_counts'],
            'hardware_count' => $hardwareCount,
        ];
    }

    private function rowMatchesView(
        HardwareFulfilmentOperationalRow $row,
        HardwareWorkspaceScope $scope,
        HardwareWorkspaceFilter $filter,
    ): bool {
        if ($scope === HardwareWorkspaceScope::Shipped) {
            return $row->isCompletedPresentation();
        }

        if ($row->isCompletedPresentation()) {
            return false;
        }

        if ($filter === HardwareWorkspaceFilter::All) {
            return true;
        }

        return $row->matchesWorkspaceFilter($filter);
    }

    private function rowFilterValue(HardwareFulfilmentOperationalRow $row): string
    {
        if ($row->isCompletedPresentation()) {
            return HardwareWorkspaceFilter::Delivered->value;
        }

        foreach ([
            ...HardwareWorkspaceFilter::needsActionFilters(),
            ...HardwareWorkspaceFilter::shippingFilters(),
        ] as $workspaceFilter) {
            if ($row->matchesWorkspaceFilter($workspaceFilter)) {
                return $workspaceFilter->value;
            }
        }

        return HardwareWorkspaceFilter::All->value;
    }

    /**
     * @param  list<int>  $fulfilmentIds
     * @return array<int, true>
     */
    private function workspaceOperableMap(User $user, array $fulfilmentIds): array
    {
        if ($fulfilmentIds === [] || ! HardwareFulfilmentAccess::allows($user)) {
            return [];
        }

        $allowed = [];
        HardwareFulfilment::query()
            ->whereIn('id', $fulfilmentIds)
            ->get()
            ->each(function (HardwareFulfilment $fulfilment) use ($user, &$allowed): void {
                if (HardwareFulfilmentNavigation::userCanOpen($user, $fulfilment)) {
                    $allowed[(int) $fulfilment->id] = true;
                }
            });

        return $allowed;
    }

    /**
     * @param  list<int>  $fulfilmentIds
     * @return array<int, int>
     */
    private function incidentIdsForFulfilments(array $fulfilmentIds): array
    {
        $orderIds = HardwareFulfilment::query()
            ->whereIn('id', $fulfilmentIds)
            ->whereNotNull('support_order_id')
            ->pluck('support_order_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

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
}
