<?php

namespace App\Support\Dashboard;

use App\Models\Incident;
use App\Services\DashboardPersonalizationService;
use App\Support\Dashboard\Contracts\DashboardAttentionScoreCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class DashboardIncidentSortComparator
{
    public function __construct(
        private readonly DashboardAttentionScoreCalculator $attentionScores,
    ) {}

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return Collection<int, Incident>
     */
    public function sort(
        Collection $incidents,
        bool $prioritizeRecentAssignments = false,
        ?string $filter = null,
        ?Carbon $now = null,
    ): Collection {
        $now ??= now();

        return $incidents
            ->sort(fn (Incident $left, Incident $right): int => $this->compare(
                $left,
                $right,
                $prioritizeRecentAssignments,
                $now,
            ))
            ->values();
    }

    public function compare(
        Incident $left,
        Incident $right,
        bool $prioritizeRecentAssignments = false,
        ?Carbon $now = null,
    ): int {
        $now ??= now();

        if ($prioritizeRecentAssignments) {
            $updatedComparison = ($right->updated_at?->timestamp ?? 0) <=> ($left->updated_at?->timestamp ?? 0);

            if ($updatedComparison !== 0) {
                return $updatedComparison;
            }
        }

        $leftKey = $this->sortKey($left, $now);
        $rightKey = $this->sortKey($right, $now);

        return $leftKey <=> $rightKey;
    }

    /**
     * @return array{int, int, int, int}
     */
    public function sortKey(Incident $incident, ?Carbon $now = null): array
    {
        $now ??= now();

        return [
            -$this->attentionScores->score($incident, $now),
            $incident->dashboardSortRank($now),
            $incident->created_at?->timestamp ?? 0,
            $incident->id,
        ];
    }

    public static function queueUsesAppointmentSort(?string $filter): bool
    {
        return $filter === DashboardPersonalizationService::QUEUE_SCHEDULED;
    }
}
