<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareWorkspaceFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin open-fulfilment waiting-reason chips (HardwareNeedsActionSqlQuery fulfilment rules).
 */
final class HardwareOpenFulfilmentWaitingReasonFilter
{
    public const FILTER_ALL = '';

    public function __construct(
        private readonly HardwareNeedsActionSqlQuery $needsActionSql,
    ) {}

    public function normalizeFilter(?string $raw): string
    {
        $value = trim((string) $raw);

        if ($value === '' || $value === self::FILTER_ALL) {
            return self::FILTER_ALL;
        }

        return HardwareWorkspaceFilter::tryFrom($value)?->value ?? 'invalid';
    }

    public function isValidFilter(string $normalized): bool
    {
        if ($normalized === 'invalid') {
            return false;
        }

        if ($normalized === self::FILTER_ALL) {
            return true;
        }

        $filter = HardwareWorkspaceFilter::tryFrom($normalized);

        return $filter !== null && $filter->isNeedsAction();
    }

    public function decorateOpenQuery(Builder $query, string $waitingReasonFilter, string $orderSearch): Builder
    {
        if ($waitingReasonFilter === self::FILTER_ALL) {
            return $query;
        }

        $enum = HardwareWorkspaceFilter::from($waitingReasonFilter);
        $reasonQuery = $this->needsActionSql->fulfilmentReasonQuery($enum, $orderSearch);

        return $query->whereIn('hardware_fulfilments.id', $reasonQuery->select('hardware_fulfilments.id'));
    }

    /**
     * @return array<string, int>
     */
    public function reasonCounts(Builder $scopedOpenQuery, string $orderSearch): array
    {
        $counts = [
            self::FILTER_ALL => (int) (clone $scopedOpenQuery)->count(),
        ];

        foreach ($this->chipFilters() as $filter) {
            $query = clone $scopedOpenQuery;
            $this->decorateOpenQuery($query, $filter->value, $orderSearch);
            $counts[$filter->value] = (int) $query->count();
        }

        return $counts;
    }

    /**
     * @return list<array{key: string, label: string, count: int, tone: string}>
     */
    public function chipOptions(array $counts): array
    {
        $chips = [[
            'key' => self::FILTER_ALL,
            'label' => 'All reasons',
            'count' => $counts[self::FILTER_ALL] ?? 0,
            'tone' => 'secondary',
        ]];

        foreach ($this->chipFilters() as $filter) {
            $chips[] = [
                'key' => $filter->value,
                'label' => $filter->label(),
                'count' => $counts[$filter->value] ?? 0,
                'tone' => $filter->tone(),
            ];
        }

        return $chips;
    }

    /**
     * @return list<HardwareWorkspaceFilter>
     */
    private function chipFilters(): array
    {
        return [
            HardwareWorkspaceFilter::NeedsAction,
            ...HardwareWorkspaceFilter::needsActionFilters(),
        ];
    }
}
