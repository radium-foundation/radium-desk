<?php

namespace App\Services\Operations;

use App\Data\Operations\IraOperationalRisk;
use App\Data\Operations\IraOperationalSnapshotData;
use App\Enums\AI\AIRiskLevel;
use App\Enums\IraRiskCategory;
use App\Enums\OperationQueue;
use App\Models\Incident;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use Illuminate\Support\Carbon;

class IraRiskDetectionService
{
    public function __construct(
        private readonly SmartAssignmentService $smartAssignmentService,
        private readonly WorkCalendarService $workCalendarService,
        private readonly TeamAvailabilityService $availabilityService,
        private readonly OperationsRoleService $roleService,
    ) {}

    /**
     * @return list<IraOperationalRisk>
     */
    public function detect(IraOperationalSnapshotData $snapshot, ?Carbon $at = null): array
    {
        $at ??= now();

        return [
            ...$this->workloadRisks($snapshot),
            ...$this->customerRisks($snapshot, $at),
            ...$this->teamRisks($snapshot, $at),
        ];
    }

    /**
     * @return list<IraOperationalRisk>
     */
    private function workloadRisks(IraOperationalSnapshotData $snapshot): array
    {
        $risks = [];
        $openCases = (int) ($snapshot->operations['open_cases'] ?? 0);
        $scheduled = (int) ($snapshot->operations['scheduled_today']
            ?? $snapshot->operations['support']['today']['scheduled']
            ?? $snapshot->operations['scheduled']
            ?? 0);
        $openThreshold = (int) config('ira.thresholds.high_open_cases', 30);
        $scheduledThreshold = (int) config('ira.thresholds.high_scheduled_appointments', 15);
        $minStaff = (int) config('ira.thresholds.min_available_staff', 2);
        $available = (int) ($snapshot->team['available'] ?? 0);

        if ($openCases >= $openThreshold) {
            $risks[] = new IraOperationalRisk(
                key: 'workload.high_open_cases',
                title: 'High Open Case Volume',
                category: IraRiskCategory::Workload,
                severity: $openCases >= ($openThreshold * 1.5) ? AIRiskLevel::High : AIRiskLevel::Medium,
                message: "{$openCases} open cases need attention — above the {$openThreshold} case threshold.",
                context: ['open_cases' => $openCases, 'threshold' => $openThreshold],
            );
        }

        if ($scheduled >= $scheduledThreshold) {
            $risks[] = new IraOperationalRisk(
                key: 'workload.high_appointments',
                title: 'Heavy Appointment Load',
                category: IraRiskCategory::Workload,
                severity: AIRiskLevel::Medium,
                message: "{$scheduled} appointments scheduled for today — capacity may be stretched.",
                context: ['scheduled' => $scheduled, 'threshold' => $scheduledThreshold],
            );
        }

        if ($available < $minStaff) {
            $risks[] = new IraOperationalRisk(
                key: 'workload.low_staffing',
                title: 'Low Staffing',
                category: IraRiskCategory::Workload,
                severity: $available === 0 ? AIRiskLevel::High : AIRiskLevel::Medium,
                message: "Only {$available} team member(s) available — below minimum of {$minStaff}.",
                context: ['available' => $available, 'threshold' => $minStaff],
            );
        }

        return $risks;
    }

    /**
     * @return list<IraOperationalRisk>
     */
    private function customerRisks(IraOperationalSnapshotData $snapshot, Carbon $at): array
    {
        $risks = [];
        $waiting = (int) ($snapshot->operations['waiting'] ?? 0);
        $overdue = (int) ($snapshot->operations['overdue'] ?? 0);
        $warning = (int) ($snapshot->operations['warning'] ?? 0);
        $waitingThreshold = (int) config('ira.thresholds.high_waiting_cases', 50);
        $slaRiskThreshold = (int) config('ira.thresholds.sla_risk_cases', 3);
        $longWaitingDays = (int) config('ira.thresholds.long_waiting_days', 7);

        if ($waiting >= $waitingThreshold) {
            $risks[] = new IraOperationalRisk(
                key: 'customer.high_waiting',
                title: 'Customers Waiting Too Long',
                category: IraRiskCategory::Customer,
                severity: AIRiskLevel::Medium,
                message: "{$waiting} customers are waiting for a response.",
                context: ['waiting' => $waiting, 'threshold' => $waitingThreshold],
            );
        }

        $slaRiskCount = $overdue + $warning;

        if ($slaRiskCount >= $slaRiskThreshold) {
            $risks[] = new IraOperationalRisk(
                key: 'customer.sla_danger',
                title: 'SLA Breach Risk',
                category: IraRiskCategory::Customer,
                severity: $overdue > 0 ? AIRiskLevel::High : AIRiskLevel::Medium,
                message: "{$slaRiskCount} cases risk SLA breach ({$overdue} overdue, {$warning} in warning).",
                context: [
                    'overdue' => $overdue,
                    'warning' => $warning,
                    'threshold' => $slaRiskThreshold,
                ],
            );
        }

        $longWaitingCount = $this->longWaitingCaseCount($longWaitingDays, $at);

        if ($longWaitingCount > 0) {
            $risks[] = new IraOperationalRisk(
                key: 'customer.long_waiting',
                title: 'Stale Waiting Cases',
                category: IraRiskCategory::Customer,
                severity: AIRiskLevel::Medium,
                message: "{$longWaitingCount} waiting customers have been idle for more than {$longWaitingDays} days.",
                context: ['count' => $longWaitingCount, 'days' => $longWaitingDays],
            );
        }

        $repeatContactCount = $this->repeatContactCaseCount($at);

        if ($repeatContactCount > 0) {
            $risks[] = new IraOperationalRisk(
                key: 'customer.repeat_contact',
                title: 'Repeated Customer Contact',
                category: IraRiskCategory::Customer,
                severity: AIRiskLevel::Low,
                message: "{$repeatContactCount} active cases show repeated customer follow-up activity today.",
                context: ['count' => $repeatContactCount],
            );
        }

        return $risks;
    }

    /**
     * @return list<IraOperationalRisk>
     */
    private function teamRisks(IraOperationalSnapshotData $snapshot, Carbon $at): array
    {
        $risks = [];
        $leaveCount = (int) ($snapshot->team['leave'] ?? 0);
        $overloadThreshold = (int) config('ira.thresholds.member_overload_cases', 8);
        $dashboardSnapshot = DashboardSnapshot::load();

        foreach ($this->teamMembers() as $member) {
            if ($this->workCalendarService->hasApprovedLeave($member, $at)) {
                continue;
            }

            $metrics = $this->smartAssignmentService->workloadMetrics($member, $dashboardSnapshot);

            if ($metrics['total'] >= $overloadThreshold) {
                $risks[] = new IraOperationalRisk(
                    key: 'team.overload.'.$member->id,
                    title: 'Overloaded Team Member',
                    category: IraRiskCategory::Team,
                    severity: $metrics['total'] >= ($overloadThreshold * 1.5) ? AIRiskLevel::High : AIRiskLevel::Medium,
                    message: "{$member->name} has {$metrics['total']} active cases ({$metrics['open_cases']} open, {$metrics['scheduled_cases']} scheduled).",
                    context: [
                        'user_id' => $member->id,
                        'name' => $member->name,
                        'open_cases' => $metrics['open_cases'],
                        'scheduled_cases' => $metrics['scheduled_cases'],
                        'total' => $metrics['total'],
                    ],
                );
            }
        }

        if ($leaveCount > 0 && (int) ($snapshot->team['available'] ?? 0) <= $leaveCount) {
            $risks[] = new IraOperationalRisk(
                key: 'team.unavailable',
                title: 'Team Availability Gap',
                category: IraRiskCategory::Team,
                severity: AIRiskLevel::Medium,
                message: "{$leaveCount} team member(s) on leave — available capacity is reduced.",
                context: ['leave' => $leaveCount],
            );
        }

        return $risks;
    }

    private function longWaitingCaseCount(int $days, Carbon $at): int
    {
        $cutoff = $at->copy()->subDays($days);

        return DashboardSnapshot::load()
            ->incidentsForQueue(OperationQueue::WaitingCustomer->value)
            ->filter(fn (Incident $incident): bool => $incident->updated_at !== null
                && $incident->updated_at->lte($cutoff))
            ->count();
    }

    private function repeatContactCaseCount(Carbon $at): int
    {
        $weekAgo = $at->copy()->subDays(7)->startOfDay();

        return Incident::query()
            ->whereIn('status', \App\Enums\IncidentStatus::operationallyActive())
            ->withCount([
                'remarks' => fn ($query) => $query->where('created_at', '>=', $weekAgo),
            ])
            ->get()
            ->filter(fn (Incident $incident): bool => $incident->remarks_count >= 2)
            ->count();
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
