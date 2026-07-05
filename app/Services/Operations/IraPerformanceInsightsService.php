<?php

namespace App\Services\Operations;

use App\Data\Operations\PerformanceInsight;
use App\Data\Operations\PerformancePeriodRange;
use App\Data\Operations\TeamMemberPerformanceMetrics;
use App\Enums\PerformanceInsightTone;
use App\Enums\PerformancePeriod;
use App\Models\User;
use Illuminate\Support\Carbon;

class IraPerformanceInsightsService
{
    public function __construct(
        private readonly TeamPerformanceMetricsService $metricsService,
        private readonly PerformancePeriodService $periodService,
        private readonly SmartAssignmentFeedbackMetricsService $feedbackMetricsService,
        private readonly WorkCalendarService $workCalendarService,
    ) {}

    /**
     * @return list<PerformanceInsight>
     */
    public function insights(
        PerformancePeriod|string|null $period = null,
        ?Carbon $customStart = null,
        ?Carbon $customEnd = null,
        ?Carbon $at = null,
    ): array {
        $at ??= now();
        $range = $this->periodService->resolve($period, $customStart, $customEnd, $at);
        $insights = [
            ...$this->supportLoadInsights($range, $at),
            ...$this->memberInsights($range, $at),
        ];

        return $this->sortInsights($insights);
    }

    /**
     * @return list<PerformanceInsight>
     */
    private function supportLoadInsights(PerformancePeriodRange $range, Carbon $at): array
    {
        if ($range->period !== PerformancePeriod::Today) {
            return [];
        }

        $pendingAppointments = \App\Models\SupportAppointment::query()
            ->whereDate('preferred_date', '>=', $at->toDateString())
            ->whereHas('incident', fn ($query) => $query->whereIn('status', \App\Enums\IncidentStatus::operationallyActive()))
            ->count();

        $threshold = max(1, (int) config('performance.high_appointment_load', 10));

        if ($pendingAppointments < $threshold) {
            return [];
        }

        return [
            new PerformanceInsight(
                message: "Support load is high today. {$pendingAppointments} appointments pending.",
                tone: PerformanceInsightTone::Attention,
                context: [
                    'pending_appointments' => $pendingAppointments,
                ],
            ),
        ];
    }

    /**
     * @return list<PerformanceInsight>
     */
    private function memberInsights(PerformancePeriodRange $range, Carbon $at): array
    {
        $insights = [];

        foreach ($this->metricsService->teamMetrics($range->period, $range->start, $range->end, $at) as $metrics) {
            $insights = [
                ...$insights,
                ...$this->scheduledCapacityInsights($metrics, $at),
                ...$this->communicationRecognitionInsights($metrics, $range),
            ];
        }

        return $insights;
    }

    /**
     * @return list<PerformanceInsight>
     */
    private function scheduledCapacityInsights(TeamMemberPerformanceMetrics $metrics, Carbon $at): array
    {
        $user = User::query()->with('workSchedule')->find($metrics->userId);

        if ($user === null || ! $this->workCalendarService->isEligibleForAssignment($user, $at)) {
            return [];
        }

        $feedback = $this->feedbackMetricsService->feedbackFor($user, $at);

        if ($feedback === null) {
            return [];
        }

        $scheduledCases = $feedback->scheduledCases;
        $remainingHours = $this->remainingWorkingHoursToday($metrics->userId, $at);

        if ($scheduledCases < 3 || $remainingHours === null || $remainingHours >= 4) {
            return [];
        }

        $ratio = (int) config('performance.scheduled_call_capacity_ratio', 2);

        if ($scheduledCases < ($remainingHours * $ratio)) {
            return [];
        }

        $hoursLabel = $remainingHours === 1 ? '1 working hour' : "{$remainingHours} working hours";

        return [
            new PerformanceInsight(
                message: "{$metrics->name} has {$scheduledCases} scheduled calls but only {$hoursLabel} left.",
                tone: PerformanceInsightTone::Attention,
                context: [
                    'user_id' => $metrics->userId,
                    'scheduled_cases' => $scheduledCases,
                    'remaining_hours' => $remainingHours,
                ],
            ),
        ];
    }

    /**
     * @return list<PerformanceInsight>
     */
    private function communicationRecognitionInsights(
        TeamMemberPerformanceMetrics $metrics,
        PerformancePeriodRange $range,
    ): array {
        if (! in_array($range->period, [PerformancePeriod::ThisWeek, PerformancePeriod::ThisMonth], true)) {
            return [];
        }

        $communications = (int) ($metrics->customerWork['customer_communications'] ?? 0);
        $threshold = max(1, (int) config('performance.high_communication_weekly', 50));

        if ($range->period === PerformancePeriod::ThisMonth) {
            $threshold *= 4;
        }

        if ($communications < $threshold) {
            return [];
        }

        $periodLabel = $range->period === PerformancePeriod::ThisWeek ? 'this week' : 'this month';

        return [
            new PerformanceInsight(
                message: "{$metrics->name} handled {$communications} customer follow-ups {$periodLabel}.",
                tone: PerformanceInsightTone::Good,
                context: [
                    'user_id' => $metrics->userId,
                    'customer_communications' => $communications,
                ],
            ),
        ];
    }

    private function remainingWorkingHoursToday(int $userId, Carbon $at): ?int
    {
        $user = User::query()->with('workSchedule')->find($userId);

        if ($user === null) {
            return null;
        }

        $schedule = $this->workCalendarService->scheduleFor($user);

        if ($schedule === null || ! $this->workCalendarService->isEligibleForAssignment($user, $at)) {
            return null;
        }

        $expectedEnd = $this->workCalendarService->expectedWorkEndAt($schedule, $at);

        if ($at->gte($expectedEnd)) {
            return 0;
        }

        return max(0, (int) ceil($at->diffInMinutes($expectedEnd) / 60));
    }

    /**
     * @param  list<PerformanceInsight>  $insights
     * @return list<PerformanceInsight>
     */
    private function sortInsights(array $insights): array
    {
        usort($insights, function (PerformanceInsight $left, PerformanceInsight $right): int {
            $toneRank = fn (PerformanceInsightTone $tone): int => match ($tone) {
                PerformanceInsightTone::Attention => 0,
                PerformanceInsightTone::Info => 1,
                PerformanceInsightTone::Good => 2,
            };

            return $toneRank($left->tone) <=> $toneRank($right->tone);
        });

        return $insights;
    }
}
