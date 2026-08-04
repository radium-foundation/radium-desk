<?php

namespace App\Support\Dashboard;

use App\Data\TeamActivityAgentRow;
use App\Enums\TeamActivityStatus;

class TeamActivityRowSorter
{
    /**
     * Active humans, then IRA, then idle humans, then off-duty humans.
     * Each bucket is sorted by latest activity (desc), working preference, then name.
     *
     * @param  list<TeamActivityAgentRow>  $humans
     */
    public function sort(array $humans, ?TeamActivityAgentRow $ira = null): array
    {
        $active = [];
        $idle = [];
        $offDuty = [];

        foreach ($humans as $agent) {
            match ($this->presenceTier($agent)) {
                1 => $idle[] = $agent,
                2 => $offDuty[] = $agent,
                default => $active[] = $agent,
            };
        }

        usort($active, $this->compare(...));
        usort($idle, $this->compare(...));
        usort($offDuty, $this->compare(...));

        $sorted = $active;

        if ($ira instanceof TeamActivityAgentRow) {
            $sorted[] = $ira;
        }

        return array_merge($sorted, $idle, $offDuty);
    }

    public function compare(TeamActivityAgentRow $left, TeamActivityAgentRow $right): int
    {
        $leftTimestamp = ($left->latestActivityAt ?? $left->latest?->at)?->getTimestamp() ?? 0;
        $rightTimestamp = ($right->latestActivityAt ?? $right->latest?->at)?->getTimestamp() ?? 0;

        if ($leftTimestamp !== $rightTimestamp) {
            return $rightTimestamp <=> $leftTimestamp;
        }

        $workingComparison = $this->workingRank($left) <=> $this->workingRank($right);

        if ($workingComparison !== 0) {
            return $workingComparison;
        }

        return strcasecmp($left->name, $right->name);
    }

    private function presenceTier(TeamActivityAgentRow $agent): int
    {
        return match ($agent->status) {
            TeamActivityStatus::Break => 1,
            TeamActivityStatus::OffDuty,
            TeamActivityStatus::AutoLogout,
            TeamActivityStatus::Offline,
            TeamActivityStatus::NotLoggedIn,
            TeamActivityStatus::NoSchedule,
            TeamActivityStatus::Leave,
            TeamActivityStatus::NotStartedShift,
            TeamActivityStatus::Logout => 2,
            default => 0,
        };
    }

    private function workingRank(TeamActivityAgentRow $agent): int
    {
        return match ($agent->status) {
            TeamActivityStatus::OffDuty,
            TeamActivityStatus::AutoLogout,
            TeamActivityStatus::Offline,
            TeamActivityStatus::NotLoggedIn,
            TeamActivityStatus::NoSchedule,
            TeamActivityStatus::Leave,
            TeamActivityStatus::NotStartedShift,
            TeamActivityStatus::Logout => 2,
            TeamActivityStatus::Break => 1,
            default => 0,
        };
    }
}
