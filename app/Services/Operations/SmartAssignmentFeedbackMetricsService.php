<?php

namespace App\Services\Operations;

use App\Data\Operations\SmartAssignmentFeedbackMetrics;
use App\Data\Operations\TeamMemberPerformanceMetrics;
use App\Enums\PerformancePeriod;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use Illuminate\Support\Carbon;

class SmartAssignmentFeedbackMetricsService
{
    public function __construct(
        private readonly TeamPerformanceMetricsService $metricsService,
        private readonly PerformancePeriodService $periodService,
        private readonly SmartAssignmentService $smartAssignmentService,
    ) {}

    public function feedbackFor(User $user, ?Carbon $at = null): ?SmartAssignmentFeedbackMetrics
    {
        if (! app(OperationsRoleService::class)->isTeamMember($user)) {
            return null;
        }

        $at ??= now();
        $range = $this->periodService->resolve(PerformancePeriod::ThisMonth, at: $at);
        $metrics = $this->metricsService->metricsFor($user, PerformancePeriod::ThisMonth, at: $at);
        $workload = $this->smartAssignmentService->workloadMetrics($user, DashboardSnapshot::load());

        $averageResolution = $this->metricsService->averageResolutionMinutes($user, $range);
        $activeHours = max(0.1, ((int) ($metrics->presence['active_desk_seconds'] ?? 0)) / 3600);
        $completedCases = max(0, (int) ($metrics->customerWork['cases_completed'] ?? 0));
        $currentEfficiency = round($completedCases / $activeHours, 2);

        $teamEfficiencies = collect($this->metricsService->teamMetrics(PerformancePeriod::ThisMonth, at: $at))
            ->map(function (TeamMemberPerformanceMetrics $memberMetrics): float {
                $hours = max(0.1, ((int) ($memberMetrics->presence['active_desk_seconds'] ?? 0)) / 3600);
                $completed = max(0, (int) ($memberMetrics->customerWork['cases_completed'] ?? 0));

                return $completed / $hours;
            })
            ->filter(fn (float $value): bool => $value > 0);

        $teamAverageEfficiency = $teamEfficiencies->isNotEmpty()
            ? (float) $teamEfficiencies->avg()
            : $currentEfficiency;

        $workloadCapacity = $teamAverageEfficiency > 0
            ? round(max(0, ($teamAverageEfficiency - $currentEfficiency) / $teamAverageEfficiency) * 100, 1)
            : null;

        return new SmartAssignmentFeedbackMetrics(
            userId: $user->id,
            averageResolutionMinutes: $averageResolution,
            currentEfficiency: $currentEfficiency,
            workloadCapacity: $workloadCapacity,
            openCases: $workload['open_cases'],
            scheduledCases: $workload['scheduled_cases'],
            activeCases: $workload['total'],
        );
    }

    /**
     * @return list<SmartAssignmentFeedbackMetrics>
     */
    public function teamFeedback(?Carbon $at = null): array
    {
        $at ??= now();
        $roleService = app(OperationsRoleService::class);

        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $roleService->operationalRoleSlugs()))
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): ?SmartAssignmentFeedbackMetrics => $this->feedbackFor($user, $at))
            ->filter()
            ->values()
            ->all();
    }
}
