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
                    );
                }

                if ($now->gte($startsAt)) {
                    return $this->badge(
                        label: 'Due Now',
                        title: 'Support appointment is due now',
                        tone: 'warning',
                        compactSymbol: '🟠',
                    );
                }

                if ($timing->isImminent($now) && $timing->minutesUntil($now) > 0) {
                    return $this->badge(
                        label: 'Starting Soon',
                        title: 'Support appointment starts within 30 minutes',
                        tone: 'warning',
                        compactSymbol: '🟡',
                    );
                }
            }
        }

        return $this->badge(
            label: 'Scheduled',
            title: 'Support appointment scheduled',
            tone: 'info',
            compactSymbol: '📅',
        );
    }

    /**
     * @return array{
     *     label: string,
     *     title: string,
     *     tone: 'info'|'warning'|'danger',
     *     compact_symbol: string,
     * }
     */
    private function badge(string $label, string $title, string $tone, string $compactSymbol): array
    {
        return [
            'label' => $label,
            'title' => $title,
            'tone' => $tone,
            'compact_symbol' => $compactSymbol,
        ];
    }
}
