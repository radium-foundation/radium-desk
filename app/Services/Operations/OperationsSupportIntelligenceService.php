<?php

namespace App\Services\Operations;

use App\Data\Operations\SupportIntelligenceSummary;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
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

    public function summary(?Carbon $at = null): SupportIntelligenceSummary
    {
        $at ??= now();
        $today = $at->copy()->startOfDay();
        $tomorrow = $today->copy()->addDay();
        $sevenDaysOut = $today->copy()->addDays(7);

        $scheduledToday = $this->appointmentsOnDate($today)->count();
        $pendingToday = $this->pendingAppointmentsOnDate($today);
        $completedToday = max(0, $scheduledToday - $pendingToday);
        $missedOverdue = $this->missedOverdueCount($today);

        $activeAppointments = fn () => SupportAppointment::query()
            ->whereHas('incident', fn ($query) => $query->whereIn('status', IncidentStatus::operationallyActive()));

        $tomorrowCount = (clone $activeAppointments())
            ->whereDate('preferred_date', $tomorrow->toDateString())
            ->count();

        $nextSevenDaysCount = (clone $activeAppointments())
            ->whereDate('preferred_date', '>=', $tomorrow->toDateString())
            ->whereDate('preferred_date', '<=', $sevenDaysOut->toDateString())
            ->count();

        $serialSummary = $this->missingSerialAutomationService->qualitySummary();

        return new SupportIntelligenceSummary(
            scheduledToday: $scheduledToday,
            completedToday: $completedToday,
            pendingToday: $pendingToday,
            missedOverdue: $missedOverdue,
            tomorrow: $tomorrowCount,
            nextSevenDays: $nextSevenDaysCount,
            serialRequested: $serialSummary->autoRequested,
            serialReceived: $serialSummary->customerReplied,
            serialStillWaiting: $this->serialStillWaitingCount(),
            teamWorkload: $this->teamWorkload($at),
        );
    }

    private function pendingAppointmentsOnDate(Carbon $date): int
    {
        $snapshot = DashboardSnapshot::load();
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
            ->whereDate('preferred_date', $date->toDateString());
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
     * @return list<array{name: string, today: int, pending: int}>
     */
    private function teamWorkload(Carbon $at): array
    {
        $snapshot = DashboardSnapshot::load();
        $today = $at->copy()->startOfDay();
        $workload = [];

        foreach ($this->teamWorkBriefingService->recipients() as $user) {
            $workload[] = [
                'name' => $user->name,
                'today' => $this->todayAppointmentsFor($user, $snapshot, $today),
                'pending' => $this->smartAssignmentService->workloadMetrics($user, $snapshot)['scheduled_cases'],
            ];
        }

        usort($workload, fn (array $left, array $right): int => ($right['today'] <=> $left['today'])
            ?: ($right['pending'] <=> $left['pending'])
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
