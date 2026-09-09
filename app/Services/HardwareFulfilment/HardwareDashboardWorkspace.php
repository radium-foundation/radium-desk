<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareDashboardQueue;
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
     *     counts: array<string, int>,
     *     queue: string,
     *     search: string,
     *     queues: list<HardwareDashboardQueue>,
     *     incidentIds: array<int, int>,
     *     operableFulfilmentIds: array<int, true>,
     *     unfilteredTotal: int
     * }
     */
    public function present(Request $request): array
    {
        $search = trim((string) $request->query('q', ''));
        $queue = trim((string) $request->query('hw_queue', ''));
        $allowed = array_map(
            static fn (HardwareDashboardQueue $row): string => $row->value,
            HardwareDashboardQueue::cases(),
        );
        if ($queue !== '' && ! in_array($queue, $allowed, true)) {
            $queue = '';
        }

        $range = $this->range($request);
        $dashboard = $this->workQueue->dashboard($range['from'], $range['to'], $search, $queue);

        return [
            'rows' => $dashboard['rows'],
            'counts' => $dashboard['counts'],
            'queue' => $queue,
            'search' => $search,
            'queues' => HardwareDashboardQueue::cases(),
            'incidentIds' => $this->incidentIds($dashboard['rows']),
            'operableFulfilmentIds' => $this->operableFulfilmentIds($dashboard['rows'], $request->user()),
            'unfilteredTotal' => $dashboard['unfiltered_total'],
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
        if ($counts === []) {
            return $counts;
        }

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

    /**
     * @return array{from: Carbon, to: Carbon}
     */
    private function range(Request $request): array
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
