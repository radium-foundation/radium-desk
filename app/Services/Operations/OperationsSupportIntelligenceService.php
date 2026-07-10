<?php

namespace App\Services\Operations;

use App\Data\Operations\MissingSerialAutomationQualitySummary;
use App\Data\Operations\SupportIntelligenceSummary;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\SupportAppointmentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use Illuminate\Support\Carbon;

class OperationsSupportIntelligenceService
{
    public function __construct(
        private readonly OperationsMissingSerialAutomationService $missingSerialAutomationService,
        private readonly TeamWorkBriefingService $teamWorkBriefingService,
        private readonly SmartAssignmentService $smartAssignmentService,
        private readonly OperationsQueueClassifier $queueClassifier,
    ) {}

    public function summary(
        ?Carbon $at = null,
        ?MissingSerialAutomationQualitySummary $serialQuality = null,
    ): SupportIntelligenceSummary {
        $at ??= now();
        $today = $at->copy()->startOfDay();
        $tomorrow = $today->copy()->addDay();
        $sevenDaysOut = $today->copy()->addDays(7);
        $snapshot = DashboardSnapshot::load();

        $scheduledToday = $this->appointmentsOnDate($today)->count();
        $pendingToday = $this->pendingAppointmentsOnDate($today, $snapshot);
        $completedToday = max(0, $scheduledToday - $pendingToday);
        $missedOverdue = $this->missedOverdueCount($today);
        $unassignedScheduled = $this->unassignedScheduledCount($snapshot);

        $activeAppointments = fn () => SupportAppointment::query()
            ->whereHas('incident', fn ($query) => $query->whereIn('status', IncidentStatus::operationallyActive()));

        $tomorrowCount = (clone $activeAppointments())
            ->whereDate('preferred_date', $tomorrow->toDateString())
            ->count();

        $nextSevenDaysCount = (clone $activeAppointments())
            ->whereDate('preferred_date', '>=', $tomorrow->toDateString())
            ->whereDate('preferred_date', '<=', $sevenDaysOut->toDateString())
            ->count();

        $serialSummary = $serialQuality ?? $this->missingSerialAutomationService->qualitySummary();
        $slaCounts = $snapshot->slaCounts($at);
        $serviceSlaCounts = $snapshot->serviceSlaCounts($at);
        $hardwareSlaCounts = $snapshot->hardwareSlaCounts($at);
        $queueCounts = $snapshot->queueCounts();

        return new SupportIntelligenceSummary(
            scheduledToday: $scheduledToday,
            completedToday: $completedToday,
            pendingToday: $pendingToday,
            missedOverdue: $missedOverdue,
            unassignedScheduled: $unassignedScheduled,
            tomorrow: $tomorrowCount,
            nextSevenDays: $nextSevenDaysCount,
            serialRequested: $serialSummary->autoRequested,
            serialReceived: $serialSummary->customerReplied,
            serialStillWaiting: $this->serialStillWaitingCount(),
            teamWorkload: $this->teamWorkload($at, $snapshot),
            operationalMetrics: [
                'action_required' => $queueCounts[OperationQueue::ActionRequired->value] ?? 0,
                'waiting' => $queueCounts[OperationQueue::WaitingCustomer->value] ?? 0,
                'service_sla_risk' => ($serviceSlaCounts['overdue_cases'] ?? 0) + ($serviceSlaCounts['warning_cases'] ?? 0),
                'service_overdue' => $serviceSlaCounts['overdue_cases'] ?? 0,
                'service_warning' => $serviceSlaCounts['warning_cases'] ?? 0,
                'hardware_sla_risk' => ($hardwareSlaCounts['overdue_cases'] ?? 0) + ($hardwareSlaCounts['warning_cases'] ?? 0),
                'hardware_overdue' => $hardwareSlaCounts['overdue_cases'] ?? 0,
                'hardware_warning' => $hardwareSlaCounts['warning_cases'] ?? 0,
                'missed_appointments' => $missedOverdue,
                'total_overdue_cases' => $slaCounts['overdue_cases'] ?? 0,
                'total_warning_cases' => $slaCounts['warning_cases'] ?? 0,
            ],
        );
    }

    private function pendingAppointmentsOnDate(Carbon $date, DashboardSnapshot $snapshot): int
    {
        $count = 0;

        $snapshot->incidentsForQueue(OperationQueue::Scheduled->value)
            ->each(function (Incident $incident) use (&$count, $date): void {
                foreach ($incident->supportAppointments as $appointment) {
                    if ($appointment->preferred_date !== null
                        && $appointment->preferred_date->isSameDay($date)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function missedOverdueCount(Carbon $today): int
    {
        return SupportAppointment::query()
            ->whereDate('preferred_date', '<', $today->toDateString())
            ->whereHas('incident', fn ($query) => $query->whereIn('status', IncidentStatus::operationallyActive()))
            ->with(['incident.order', 'incident.supportAppointments'])
            ->get()
            ->filter(fn (SupportAppointment $appointment): bool => $this->isMissedOverdueAppointment($appointment, $today))
            ->count();
    }

    private function isMissedOverdueAppointment(SupportAppointment $appointment, Carbon $today): bool
    {
        if ($appointment->preferred_date === null || ! $appointment->preferred_date->lt($today)) {
            return false;
        }

        $incident = $appointment->incident;

        if ($this->queueClassifier->isCompleted($incident)) {
            return false;
        }

        return ! $this->hasSupersedingAppointment($appointment);
    }

    private function hasSupersedingAppointment(SupportAppointment $appointment): bool
    {
        $incident = $appointment->incident;

        return $incident->supportAppointments->contains(
            fn (SupportAppointment $other): bool => $other->id !== $appointment->id
                && $other->preferred_date !== null
                && $other->preferred_date->greaterThan($appointment->preferred_date),
        );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<SupportAppointment>
     */
    private function appointmentsOnDate(Carbon $date)
    {
        return SupportAppointment::query()
            ->scheduled()
            ->whereDate('preferred_date', $date->toDateString());
    }

    private function unassignedScheduledCount(DashboardSnapshot $snapshot): int
    {
        return $snapshot->incidentsForQueue(OperationQueue::Scheduled->value)
            ->filter(fn (Incident $incident): bool => $incident->assigned_to_user_id === null)
            ->count();
    }

    private function serialStillWaitingCount(): int
    {
        return Order::query()
            ->whereNotNull('missing_serial_first_requested_at')
            ->where(function ($query): void {
                $query->whereNull('serial_entered_at')
                    ->orWhereColumn('serial_entered_at', '<', 'missing_serial_first_requested_at');
            })
            ->count();
    }

    /**
     * @return list<array{name: string, today: int, pending: int, active_cases: int}>
     */
    private function teamWorkload(Carbon $at, DashboardSnapshot $snapshot): array
    {
        $today = $at->copy()->startOfDay();
        $workload = [];

        foreach ($this->teamWorkBriefingService->recipients() as $user) {
            $metrics = $this->smartAssignmentService->workloadMetrics($user, $snapshot);

            $workload[] = [
                'name' => $user->name,
                'today' => $this->todayAppointmentsFor($user, $snapshot, $today),
                'action_needed' => $metrics['open_cases'],
                'scheduled_today' => $metrics['scheduled_today'],
                'scheduled_future' => $metrics['scheduled_future'],
                'pending' => $metrics['scheduled_future'],
                'active_cases' => $metrics['total'],
            ];
        }

        usort($workload, fn (array $left, array $right): int => ($right['active_cases'] <=> $left['active_cases'])
            ?: ($right['today'] <=> $left['today'])
            ?: ($right['scheduled_future'] <=> $left['scheduled_future'])
            ?: strcmp($left['name'], $right['name']));

        return $workload;
    }

    private function todayAppointmentsFor(User $user, DashboardSnapshot $snapshot, Carbon $today): int
    {
        $count = 0;

        $snapshot->activeIncidents()
            ->filter(fn (Incident $incident): bool => $incident->assigned_to_user_id === $user->id)
            ->each(function (Incident $incident) use (&$count, $today): void {
                foreach ($incident->supportAppointments as $appointment) {
                    if ($appointment->preferred_date !== null
                        && $appointment->preferred_date->isSameDay($today)) {
                        $count++;
                    }
                }
            });

        return $count;
    }
}
