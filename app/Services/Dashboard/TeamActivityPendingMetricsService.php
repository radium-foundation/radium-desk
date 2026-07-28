<?php

namespace App\Services\Dashboard;

use App\Data\TeamActivityPendingMetrics;
use App\Models\User;
use App\ReadModels\Cases\CaseQueueReadModel;

class TeamActivityPendingMetricsService
{
    public function __construct(
        private readonly CaseQueueReadModel $caseQueueReadModel,
    ) {}

    /**
     * @param  list<int>  $userIds
     * @return array<int, TeamActivityPendingMetrics>
     */
    public function forUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $users = User::query()
            ->whereIn('id', $userIds)
            ->get(['id']);

        if ($users->isEmpty()) {
            return [];
        }

        $workloads = $this->caseQueueReadModel->workloadForTeamMembers($users);
        $metrics = [];

        foreach ($workloads as $userId => $counts) {
            $metrics[(int) $userId] = new TeamActivityPendingMetrics(
                pendingCount: (int) ($counts['pending'] ?? 0),
                overdueCount: (int) ($counts['overdue'] ?? 0),
            );
        }

        return $metrics;
    }
}
