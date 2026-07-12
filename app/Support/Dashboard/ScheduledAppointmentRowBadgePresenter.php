<?php

namespace App\Support\Dashboard;

use App\Data\Dashboard\AgentNextAppointment;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\SupportAppointment;
use App\Services\Operations\OperationsSupportIntelligenceService;
use App\Services\Operations\TeamTelegramQuietRulesService;
use App\Support\Customer360\ScheduledSupportAppointmentContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ScheduledAppointmentRowBadgePresenter
{
    public function __construct(
        private readonly ScheduledSupportAppointmentContext $appointmentContext,
        private readonly TeamTelegramQuietRulesService $slotRules,
        private readonly OperationsSupportIntelligenceService $supportIntelligence,
    ) {}

    /**
     * @return array{
     *     label: string,
     *     title: string,
     *     tone: 'info'|'warning'|'danger',
     *     compact_symbol: string,
     *     schedule_summary?: string,
     * }|null
     */
    public function present(Incident $incident, ?Carbon $now = null): ?array
    {
        $now ??= now();
        $context = $this->appointmentContext->forIncident($incident);

        if (! is_array($context) || ! ($context['is_active'] ?? false)) {
            return null;
        }

        $appointment = $this->appointmentContext->appointmentForIncident($incident);

        if (! $appointment instanceof SupportAppointment) {
            return null;
        }

        $today = $now->copy()->startOfDay();

        if ($this->supportIntelligence->isMissedOverdueAppointment($appointment, $today)) {
            return $this->badge(
                label: 'Missed',
                title: 'Scheduled support appointment was missed',
                tone: 'danger',
                compactSymbol: '🔴',
                appointment: $appointment,
                now: $now,
            );
        }

        $preferredDate = $appointment->preferred_date;
        $slot = $appointment->preferred_time_slot;

        if ($preferredDate === null || ! $slot instanceof SupportAppointmentTimeSlot) {
            return $this->badge(
                label: 'Scheduled',
                title: 'Support appointment scheduled',
                tone: 'info',
                compactSymbol: '📅',
                appointment: $appointment,
                now: $now,
            );
        }

        if ($preferredDate->isSameDay($today)) {
            $startsAt = $this->slotRules->slotStartAt($slot, $today);
            $endsAt = $this->slotRules->slotEndAt($slot, $today);

            if ($startsAt instanceof Carbon && $endsAt instanceof Carbon) {
                $timing = new AgentNextAppointment(
                    incidentId: $incident->id,
                    customerName: '',
                    deviceModel: null,
                    startsAt: $startsAt,
                    isOverdue: $startsAt->lt($now),
                );

                if ($now->gte($endsAt)) {
                    return $this->badge(
                        label: 'Follow-up Required',
                        title: 'Support appointment slot ended; follow up with the customer',
                        tone: 'danger',
                        compactSymbol: '⚠️',
                        appointment: $appointment,
                        now: $now,
                    );
                }

                if ($now->gte($startsAt)) {
                    return $this->badge(
                        label: 'Due Now',
                        title: 'Support appointment is due now',
                        tone: 'warning',
                        compactSymbol: '🟠',
                        appointment: $appointment,
                        now: $now,
                    );
                }

                if ($timing->isImminent($now) && $timing->minutesUntil($now) > 0) {
                    return $this->badge(
                        label: 'Starting Soon',
                        title: 'Support appointment starts within 30 minutes',
                        tone: 'warning',
                        compactSymbol: '🟡',
                        appointment: $appointment,
                        now: $now,
                    );
                }
            }
        }

        return $this->badge(
            label: 'Scheduled',
            title: 'Support appointment scheduled',
            tone: 'info',
            compactSymbol: '📅',
            appointment: $appointment,
            now: $now,
        );
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return Collection<int, Incident>
     */
    public function sortIncidents(Collection $incidents, ?Carbon $now = null): Collection
    {
        $now ??= now();

        return $incidents
            ->sort(fn (Incident $left, Incident $right): int => $this->compareIncidentsForSort($left, $right, $now))
            ->values();
    }

    public function compareIncidentsForSort(Incident $left, Incident $right, ?Carbon $now = null): int
    {
        $now ??= now();

        return $this->sortKey($left, $now) <=> $this->sortKey($right, $now);
    }

    /**
     * @return array{int, int, int, int}
     */
    public function sortKey(Incident $incident, ?Carbon $now = null): array
    {
        $now ??= now();
        $presentation = $this->present($incident, $now);
        $label = $presentation['label'] ?? 'Scheduled';

        $urgencyRank = match ($label) {
            'Missed' => 0,
            'Due Now' => 1,
            'Starting Soon' => 2,
            'Follow-up Required' => 3,
            default => 4,
        };

        $appointment = $this->appointmentContext->appointmentForIncident($incident);
        $preferredDate = $appointment?->preferred_date;
        $dateTimestamp = $preferredDate?->copy()->startOfDay()->timestamp ?? PHP_INT_MAX;

        $slotStartTimestamp = PHP_INT_MAX;
        $slot = $appointment?->preferred_time_slot;

        if ($preferredDate !== null && $slot instanceof SupportAppointmentTimeSlot) {
            $startsAt = $this->slotRules->slotStartAt($slot, $preferredDate->copy()->startOfDay());
            $slotStartTimestamp = $startsAt?->timestamp ?? PHP_INT_MAX;
        }

        return [$urgencyRank, $dateTimestamp, $slotStartTimestamp, $incident->id];
    }

    public function scheduleSummary(?SupportAppointment $appointment, ?Carbon $now = null): ?string
    {
        if (! $appointment instanceof SupportAppointment
            || $appointment->preferred_date === null
            || ! $appointment->preferred_time_slot instanceof SupportAppointmentTimeSlot) {
            return null;
        }

        $now ??= now();
        $today = $now->copy()->startOfDay();
        $preferredDate = $appointment->preferred_date->copy()->startOfDay();

        $dayLabel = match (true) {
            $preferredDate->isSameDay($today) => 'Today',
            $preferredDate->isSameDay($today->copy()->addDay()) => 'Tomorrow',
            default => $preferredDate->format('M j'),
        };

        $slotLabel = ucfirst($appointment->preferred_time_slot->value);

        return "{$dayLabel} • {$slotLabel}";
    }

    /**
     * @return array{
     *     label: string,
     *     title: string,
     *     tone: 'info'|'warning'|'danger',
     *     compact_symbol: string,
     *     schedule_summary?: string,
     * }
     */
    private function badge(
        string $label,
        string $title,
        string $tone,
        string $compactSymbol,
        ?SupportAppointment $appointment = null,
        ?Carbon $now = null,
    ): array {
        $badge = [
            'label' => $label,
            'title' => $title,
            'tone' => $tone,
            'compact_symbol' => $compactSymbol,
        ];

        $scheduleSummary = $this->scheduleSummary($appointment, $now);

        if ($scheduleSummary !== null) {
            $badge['schedule_summary'] = $scheduleSummary;
        }

        return $badge;
    }
}
