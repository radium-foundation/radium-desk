<?php

namespace App\Services\Operations;

use App\Data\Operations\HumanEffortOutcomeMetrics;
use App\Enums\OperationsKpiProfile;
use App\Models\User;
use Illuminate\Support\Carbon;

class RoleAwareKpiMetricsService
{
    public function __construct(
        private readonly OperationsKpiProfileResolver $profileResolver,
        private readonly SupportActivityMetricsService $supportMetricsService,
        private readonly AdminActivationMetricsService $activationMetricsService,
    ) {}

    public function profileFor(User $user): OperationsKpiProfile
    {
        return $this->profileResolver->resolve($user);
    }

    public function metricsFor(User $user, ?Carbon $dayStart = null): HumanEffortOutcomeMetrics
    {
        $dayStart ??= now()->startOfDay();

        return $this->metricsForUsers([$user->id], $dayStart)[$user->id]
            ?? new HumanEffortOutcomeMetrics(
                profile: $this->profileFor($user),
                outcome: 0,
                effort: 0,
            );
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, HumanEffortOutcomeMetrics>
     */
    public function metricsForUsers(array $userIds, ?Carbon $dayStart = null): array
    {
        if ($userIds === []) {
            return [];
        }

        $dayStart ??= now()->startOfDay();
        $dayEnd = now();
        $profiles = $this->profileResolver->resolveForUsers($userIds);
        $supportIds = [];
        $activationIds = [];

        foreach ($userIds as $userId) {
            $profile = $profiles[$userId] ?? OperationsKpiProfile::Support;

            if ($profile === OperationsKpiProfile::Activation) {
                $activationIds[] = $userId;
            } else {
                $supportIds[] = $userId;
            }
        }

        $metrics = [];

        if ($supportIds !== []) {
            $metrics += $this->supportMetricsService->metricsForUsers($supportIds, $dayStart);
        }

        if ($activationIds !== []) {
            $metrics += $this->activationMetricsService->metricsForUsers($activationIds, $dayStart, $dayEnd);
        }

        return $metrics;
    }

    /**
     * @return array<string, int|float>
     */
    public function teamTotals(?Carbon $at = null): array
    {
        $at ??= now();
        $dayStart = $at->copy()->startOfDay();

        $supportOutcome = 0;
        $supportEffort = 0;
        $activationOutcome = 0;
        $activationEffort = 0;
        $failedActivations = 0;
        $driverGuidesSent = 0;

        foreach ($this->metricsForUsers($this->trackedUserIds(), $dayStart) as $metrics) {
            if ($metrics->profile === OperationsKpiProfile::Activation) {
                $activationOutcome += $metrics->outcome;
                $activationEffort += $metrics->effort;
                $failedActivations += (int) ($metrics->breakdown['failed_activations'] ?? 0);
                $driverGuidesSent += (int) ($metrics->breakdown['driver_guides_sent'] ?? 0);

                continue;
            }

            $supportOutcome += $metrics->outcome;
            $supportEffort += $metrics->effort;
        }

        return [
            'support_cases_worked' => $supportOutcome,
            'support_customer_touches' => $supportEffort,
            'activation_orders_activated' => $activationOutcome,
            'activation_sessions' => $activationEffort,
            'failed_activations' => $failedActivations,
            'driver_guides_sent' => $driverGuidesSent,
            // Backward-compatible aliases used by IRA snapshots.
            'completed_cases' => $supportOutcome,
            'customer_communications' => $supportEffort,
        ];
    }

    /**
     * @return list<int>
     */
    private function trackedUserIds(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn(
                'name',
                app(OperationsRoleService::class)->attendanceTrackedRoleSlugs(),
            ))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
