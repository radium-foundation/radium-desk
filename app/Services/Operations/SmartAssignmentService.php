<?php

namespace App\Services\Operations;

use App\Data\Operations\SmartAssignmentResult;
use App\Enums\OperationQueue;
use App\Enums\TeamAvailabilityStatus;
use App\Models\Incident;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SmartAssignmentService
{
    public function __construct(
        private readonly TeamAvailabilityService $availabilityService,
        private readonly WorkCalendarService $workCalendarService,
        private readonly TeamMemberActivityService $activityService,
        private readonly OperationsQueueClassifier $queueClassifier,
        private readonly OperationsAssignmentEligibilityService $assignmentEligibilityService,
    ) {}

    public function resolveBestAssignee(?Carbon $at = null): SmartAssignmentResult
    {
        $candidates = $this->eligibleCandidates($at);

        if ($candidates === []) {
            return SmartAssignmentResult::unassigned('no_eligible_team_members');
        }

        $snapshot = DashboardSnapshot::load();
        $scored = $this->scoreCandidates($candidates, $snapshot);
        $best = $scored[0];
        $metrics = $best['metrics'];
        $status = $this->availabilityService->statusFor($best['user']);

        return SmartAssignmentResult::assigned(
            assignee: $best['user'],
            reasons: $best['reasons'],
            context: [
                'factors' => $best['reasons'],
                'availability' => $status->value,
                'availability_label' => $status->label(),
                'open_cases' => $metrics['open_cases'],
                'scheduled_cases' => $metrics['scheduled_cases'],
                'active_cases' => $metrics['total'],
                'assignee_name' => $best['user']->name,
            ],
        );
    }

    /**
     * @return list<User>
     */
    public function eligibleCandidates(?Carbon $at = null): array
    {
        return User::query()
            ->where('is_active', true)
            ->role(RolePermissionSeeder::SUPPORT_TEAM_ROLES)
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $this->assignmentEligibilityService->isEligible($user, $at))
            ->values()
            ->all();
    }

    public function isEligible(User $user, ?Carbon $at = null): bool
    {
        return $this->assignmentEligibilityService->isEligible($user, $at);
    }

    /**
     * @return array{
     *     open_cases: int,
     *     scheduled_today: int,
     *     scheduled_future: int,
     *     scheduled_cases: int,
     *     total: int,
     *     scheduled_total: int
     * }
     */
    public function workloadMetrics(User $user, ?DashboardSnapshot $snapshot = null): array
    {
        $snapshot ??= DashboardSnapshot::load();
        $today = now()->startOfDay();

        $assigned = $snapshot->activeIncidents()
            ->filter(fn (Incident $incident): bool => $incident->assigned_to_user_id === $user->id);

        $scheduledToday = 0;
        $scheduledFuture = 0;

        $assigned
            ->filter(fn (Incident $incident): bool => $this->queueClassifier->isScheduled($incident))
            ->each(function (Incident $incident) use (&$scheduledToday, &$scheduledFuture, $today): void {
                $hasToday = false;
                $hasFuture = false;

                foreach ($incident->supportAppointments as $appointment) {
                    if ($appointment->preferred_date === null || $appointment->preferred_date->lt($today)) {
                        continue;
                    }

                    if ($appointment->preferred_date->isSameDay($today)) {
                        $hasToday = true;
                    } else {
                        $hasFuture = true;
                    }
                }

                if ($hasToday) {
                    $scheduledToday++;
                }

                if ($hasFuture) {
                    $scheduledFuture++;
                }
            });

        $openCases = $assigned
            ->filter(function (Incident $incident): bool {
                $queue = $this->queueClassifier->classify($incident);

                return in_array($queue, [
                    OperationQueue::ActionRequired,
                    OperationQueue::Attention,
                ], true);
            })
            ->count();

        $scheduledTotal = $scheduledToday + $scheduledFuture;

        return [
            'open_cases' => $openCases,
            'scheduled_today' => $scheduledToday,
            'scheduled_future' => $scheduledFuture,
            'scheduled_cases' => $scheduledTotal,
            'total' => $openCases + $scheduledToday,
            'scheduled_total' => $scheduledTotal,
        ];
    }

    /**
     * @param  list<User>  $candidates
     * @return list<array{user: User, metrics: array{open_cases: int, scheduled_today: int, scheduled_future: int, scheduled_cases: int, total: int, scheduled_total: int}, reasons: list<string>, sort_key: list<int>}>
     */
    private function scoreCandidates(array $candidates, DashboardSnapshot $snapshot): array
    {
        $lookbackHours = max(1, (int) config('smart_assignment.activity_lookback_hours', 2));
        $hasAlternativesWithRecentActivity = collect($candidates)->contains(
            fn (User $user): bool => $this->hasRecentWorkActivity($user, $lookbackHours),
        );

        $scored = [];

        foreach ($candidates as $user) {
            $metrics = $this->workloadMetrics($user, $snapshot);
            $status = $this->availabilityService->statusFor($user);

            $scored[] = [
                'user' => $user,
                'metrics' => $metrics,
                'reasons' => $this->buildReasons($status, $metrics),
                'sort_key' => [
                    $status === TeamAvailabilityStatus::Available ? 0 : 1,
                    $metrics['total'],
                    $this->activityPenalty($user, $hasAlternativesWithRecentActivity, $lookbackHours),
                    $user->id,
                ],
            ];
        }

        usort($scored, fn (array $left, array $right): int => $left['sort_key'] <=> $right['sort_key']);

        return $scored;
    }

    /**
     * @param  array{open_cases: int, scheduled_today: int, scheduled_future: int, scheduled_cases: int, total: int, scheduled_total: int}  $metrics
     * @return list<string>
     */
    private function buildReasons(TeamAvailabilityStatus $status, array $metrics): array
    {
        $reasons = [$status->label()];

        $reasons[] = 'Lowest workload';

        $total = $metrics['total'];
        $reasons[] = $total.' active case'.($total === 1 ? '' : 's');

        return $reasons;
    }

    private function hasRecentWorkActivity(User $user, int $lookbackHours): bool
    {
        $lastActivity = $this->activityService->lastWorkActivityAt($user);

        if ($lastActivity === null) {
            return false;
        }

        return $lastActivity->gte(now()->subHours($lookbackHours));
    }

    private function activityPenalty(User $user, bool $hasAlternativesWithRecentActivity, int $lookbackHours): int
    {
        if (! $hasAlternativesWithRecentActivity) {
            return 0;
        }

        return $this->hasRecentWorkActivity($user, $lookbackHours) ? 0 : 1;
    }
}
