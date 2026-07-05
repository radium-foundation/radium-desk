<?php

namespace App\Services\Operations;

use App\Data\Operations\IraOperationalRecommendation;
use App\Data\Operations\IraOperationalRisk;
use App\Data\Operations\IraOperationalSnapshotData;
use App\Enums\OperationQueue;
use App\Enums\TeamAvailabilityStatus;
use App\Models\Incident;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use Illuminate\Support\Carbon;

class IraRecommendationEngineService
{
    public function __construct(
        private readonly SmartAssignmentService $smartAssignmentService,
        private readonly TeamAvailabilityService $availabilityService,
        private readonly WorkCalendarService $workCalendarService,
        private readonly OperationsRoleService $roleService,
        private readonly IraMemoryService $memoryService,
    ) {}

    /**
     * @param  list<IraOperationalRisk>  $risks
     * @return list<IraOperationalRecommendation>
     */
    public function recommend(
        IraOperationalSnapshotData $snapshot,
        array $risks,
        ?Carbon $at = null,
    ): array {
        $at ??= now();
        $recommendations = [
            ...$this->capacityRecommendations($at),
            ...$this->slaRecommendations($risks),
            ...$this->waitingRecommendations($risks),
            ...$this->trendRecommendations($snapshot, $at),
            ...$this->productTrendRecommendations($at),
        ];

        return $this->deduplicate($recommendations);
    }

    /**
     * @return list<IraOperationalRecommendation>
     */
    private function capacityRecommendations(Carbon $at): array
    {
        $recommendations = [];
        $dashboardSnapshot = DashboardSnapshot::load();
        $unassignedScheduled = $dashboardSnapshot
            ->incidentsForQueue(OperationQueue::Scheduled->value)
            ->filter(fn (Incident $incident): bool => $incident->assigned_to_user_id === null)
            ->count();

        foreach ($this->teamMembers() as $member) {
            if (! $this->smartAssignmentService->isEligible($member, $at)) {
                continue;
            }

            $metrics = $this->smartAssignmentService->workloadMetrics($member, $dashboardSnapshot);
            $status = $this->availabilityService->statusFor($member);

            if ($metrics['total'] >= 5 || $status !== TeamAvailabilityStatus::Available) {
                continue;
            }

            if ($unassignedScheduled > 0) {
                $recommendations[] = new IraOperationalRecommendation(
                    key: 'capacity.assign.'.$member->id,
                    message: "{$member->name} has capacity. {$unassignedScheduled} unassigned scheduled case(s) available.",
                    actionUrl: route('dashboard', ['filter' => 'scheduled']),
                    context: [
                        'user_id' => $member->id,
                        'unassigned_scheduled' => $unassignedScheduled,
                        'open_cases' => $metrics['open_cases'],
                    ],
                );
            } else {
                $recommendations[] = new IraOperationalRecommendation(
                    key: 'capacity.action.'.$member->id,
                    message: "{$member->name} has capacity. {$metrics['open_cases']} open case(s) assigned with room for more.",
                    actionUrl: route('dashboard'),
                    context: [
                        'user_id' => $member->id,
                        'open_cases' => $metrics['open_cases'],
                    ],
                );
            }
        }

        return $recommendations;
    }

    /**
     * @param  list<IraOperationalRisk>  $risks
     * @return list<IraOperationalRecommendation>
     */
    private function slaRecommendations(array $risks): array
    {
        foreach ($risks as $risk) {
            if ($risk->key === 'customer.sla_danger') {
                $overdue = (int) ($risk->context['overdue'] ?? 0);

                return [
                    new IraOperationalRecommendation(
                        key: 'sla.prioritize_overdue',
                        message: $overdue > 0
                            ? "Assign overdue cases first — {$overdue} case(s) have breached SLA."
                            : 'Review warning cases before they breach SLA.',
                        actionUrl: route('dashboard', ['filter' => 'overdue']),
                        context: $risk->context,
                    ),
                ];
            }
        }

        return [];
    }

    /**
     * @param  list<IraOperationalRisk>  $risks
     * @return list<IraOperationalRecommendation>
     */
    private function waitingRecommendations(array $risks): array
    {
        foreach ($risks as $risk) {
            if ($risk->key === 'customer.long_waiting') {
                $days = (int) ($risk->context['days'] ?? 7);
                $count = (int) ($risk->context['count'] ?? 0);

                return [
                    new IraOperationalRecommendation(
                        key: 'waiting.send_reminders',
                        message: "Waiting customers older than {$days} days should receive a reminder ({$count} case(s)).",
                        actionUrl: route('dashboard', ['queue' => 'waiting_customer']),
                        context: $risk->context,
                    ),
                ];
            }
        }

        return [];
    }

    /**
     * @return list<IraOperationalRecommendation>
     */
    private function trendRecommendations(IraOperationalSnapshotData $snapshot, Carbon $at): array
    {
        $deltas = $this->memoryService->compareWithYesterday($at);
        $openDelta = (int) ($deltas['operations.open_cases'] ?? 0);

        if ($openDelta <= 0) {
            return [];
        }

        $yesterdayOpen = max(0, (int) ($snapshot->operations['open_cases'] ?? 0) - $openDelta);

        if ($yesterdayOpen === 0) {
            return [];
        }

        $percentIncrease = (int) round(($openDelta / $yesterdayOpen) * 100);

        if ($percentIncrease < 20) {
            return [];
        }

        return [
            new IraOperationalRecommendation(
                key: 'trend.open_cases_increase',
                message: "Open cases increased {$percentIncrease}% since yesterday.",
                context: [
                    'delta' => $openDelta,
                    'percent_increase' => $percentIncrease,
                ],
            ),
        ];
    }

    /**
     * @return list<IraOperationalRecommendation>
     */
    private function productTrendRecommendations(Carbon $at): array
    {
        $thisWeekStart = $at->copy()->startOfWeek();
        $lastWeekStart = $thisWeekStart->copy()->subWeek();
        $lastWeekEnd = $thisWeekStart->copy()->subDay();

        $prefix = (string) config('operations.hardware_order_prefix', 'FM220');

        $thisWeekCount = Incident::query()
            ->where('created_at', '>=', $thisWeekStart)
            ->whereHas('order', fn ($query) => $query->where('order_id', 'like', $prefix.'%'))
            ->count();

        $lastWeekCount = Incident::query()
            ->whereBetween('created_at', [$lastWeekStart, $lastWeekEnd->endOfDay()])
            ->whereHas('order', fn ($query) => $query->where('order_id', 'like', $prefix.'%'))
            ->count();

        if ($lastWeekCount === 0 || $thisWeekCount <= $lastWeekCount) {
            return [];
        }

        $percentIncrease = (int) round((($thisWeekCount - $lastWeekCount) / $lastWeekCount) * 100);

        if ($percentIncrease < 25) {
            return [];
        }

        return [
            new IraOperationalRecommendation(
                key: 'trend.product.'.$prefix,
                message: "{$prefix} support requests increased {$percentIncrease}% this week.",
                context: [
                    'prefix' => $prefix,
                    'this_week' => $thisWeekCount,
                    'last_week' => $lastWeekCount,
                    'percent_increase' => $percentIncrease,
                ],
            ),
        ];
    }

    /**
     * @param  list<IraOperationalRecommendation>  $recommendations
     * @return list<IraOperationalRecommendation>
     */
    private function deduplicate(array $recommendations): array
    {
        $seen = [];
        $unique = [];

        foreach ($recommendations as $recommendation) {
            if (isset($seen[$recommendation->key])) {
                continue;
            }

            $seen[$recommendation->key] = true;
            $unique[] = $recommendation;
        }

        return $unique;
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function teamMembers()
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $this->roleService->operationalRoleSlugs()))
            ->get();
    }
}
