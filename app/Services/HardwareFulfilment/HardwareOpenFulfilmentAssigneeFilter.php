<?php

namespace App\Services\HardwareFulfilment;

use App\Models\User;
use App\Services\ServiceCaseOrderAssignmentRoutingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Admin open-fulfilment queue filters by service-case assignee (incidents.assigned_to_user_id).
 */
final class HardwareOpenFulfilmentAssigneeFilter
{
    public const FILTER_ALL = '';

    public const FILTER_UNASSIGNED = 'unassigned';

    public function __construct(
        private readonly ServiceCaseOrderAssignmentRoutingService $assignmentRouting,
    ) {}

    public function normalizeFilter(?string $raw): string
    {
        $value = trim((string) $raw);

        if ($value === '' || $value === self::FILTER_ALL) {
            return self::FILTER_ALL;
        }

        if ($value === self::FILTER_UNASSIGNED) {
            return self::FILTER_UNASSIGNED;
        }

        if (ctype_digit($value) && (int) $value > 0) {
            return $value;
        }

        return 'invalid';
    }

    public function isValidFilter(string $normalized): bool
    {
        if ($normalized === 'invalid') {
            return false;
        }

        if ($normalized === self::FILTER_ALL || $normalized === self::FILTER_UNASSIGNED) {
            return true;
        }

        $user = User::query()->find((int) $normalized);

        return $user !== null && $this->assignmentRouting->canOperateHardwareFulfilment($user);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function selectableOperators(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $this->assignmentRouting->canOperateHardwareFulfilment($user))
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->values()
            ->all();
    }

    public function decorateOpenQuery(Builder $query, string $assigneeFilter): Builder
    {
        $query = $this->joinLatestIncident($query);
        $query->addSelect('open_case_incidents.assigned_to_user_id as open_case_assignee_id');

        if ($assigneeFilter === self::FILTER_ALL) {
            return $query;
        }

        if ($assigneeFilter === self::FILTER_UNASSIGNED) {
            return $query->where(function (Builder $inner): void {
                $inner->whereNull('hardware_fulfilments.support_order_id')
                    ->orWhereNull('open_case_incidents.assigned_to_user_id');
            });
        }

        return $query->where('open_case_incidents.assigned_to_user_id', (int) $assigneeFilter);
    }

    /**
     * @return array{all: int, unassigned: int, by_user: array<int, int>}
     */
    public function workloadCounts(Builder $baseQuery): array
    {
        $query = clone $baseQuery;
        $query = $this->joinLatestIncident($query);

        $rows = $query
            ->select('hardware_fulfilments.id')
            ->addSelect('open_case_incidents.assigned_to_user_id as open_case_assignee_id')
            ->get();

        $byUser = [];
        $unassigned = 0;
        foreach ($rows as $row) {
            $assigneeId = $row->open_case_assignee_id;
            if ($assigneeId === null) {
                $unassigned++;

                continue;
            }
            $byUser[(int) $assigneeId] = ($byUser[(int) $assigneeId] ?? 0) + 1;
        }

        return [
            'all' => $rows->count(),
            'unassigned' => $unassigned,
            'by_user' => $byUser,
        ];
    }

    /**
     * @param  array{all: int, unassigned: int, by_user: array<int, int>}  $workload
     * @return list<array{key: string, label: string, count: int, user_id: ?int}>
     */
    public function chipOptions(array $workload, Collection $operatorNames): array
    {
        $chips = [[
            'key' => self::FILTER_ALL,
            'label' => 'All assignees',
            'count' => $workload['all'],
            'user_id' => null,
        ], [
            'key' => self::FILTER_UNASSIGNED,
            'label' => 'Unassigned',
            'count' => $workload['unassigned'],
            'user_id' => null,
        ]];

        foreach ($workload['by_user'] as $userId => $count) {
            if ($count <= 0) {
                continue;
            }
            $name = $operatorNames->get($userId);
            if ($name === null) {
                continue;
            }
            $chips[] = [
                'key' => (string) $userId,
                'label' => $name,
                'count' => $count,
                'user_id' => $userId,
            ];
        }

        usort($chips, static function (array $a, array $b): int {
            if ($a['key'] === self::FILTER_ALL) {
                return -1;
            }
            if ($b['key'] === self::FILTER_ALL) {
                return 1;
            }
            if ($a['key'] === self::FILTER_UNASSIGNED) {
                return -1;
            }
            if ($b['key'] === self::FILTER_UNASSIGNED) {
                return 1;
            }

            return strcasecmp($a['label'], $b['label']);
        });

        return $chips;
    }

    private function joinLatestIncident(Builder $query): Builder
    {
        if ($this->queryAlreadyJoined($query)) {
            return $query;
        }

        return $query->leftJoin('incidents as open_case_incidents', function ($join): void {
            $join->on('open_case_incidents.order_id', '=', 'hardware_fulfilments.support_order_id')
                ->whereIn('open_case_incidents.id', function ($sub): void {
                    $sub->selectRaw('MAX(id)')
                        ->from('incidents')
                        ->whereColumn('order_id', 'hardware_fulfilments.support_order_id');
                });
        });
    }

    private function queryAlreadyJoined(Builder $query): bool
    {
        $joins = $query->getQuery()->joins ?? [];

        foreach ($joins as $join) {
            if (($join->table ?? '') === 'incidents as open_case_incidents') {
                return true;
            }
        }

        return false;
    }
}
