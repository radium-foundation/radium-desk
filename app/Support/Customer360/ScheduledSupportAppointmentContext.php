<?php

namespace App\Support\Customer360;

use App\Enums\SupportAppointmentStatus;
use App\Models\Incident;
use App\Models\SupportAppointment;
use Illuminate\Support\Carbon;

class ScheduledSupportAppointmentContext
{
    /**
     * @return array{preferred_date: Carbon, time_slot_label: ?string, assignee_name: ?string}|null
     */
    public function forIncident(Incident $incident): ?array
    {
        if (! $incident->isActive()) {
            return null;
        }

        $incident->loadMissing([
            'supportAppointments' => fn ($query) => $query
                ->where('status', SupportAppointmentStatus::Scheduled)
                ->latest('preferred_date')
                ->latest('id'),
            'assignee',
        ]);

        $appointment = $incident->supportAppointments->first();

        if (! $appointment instanceof SupportAppointment) {
            return null;
        }

        return [
            'preferred_date' => $appointment->preferred_date,
            'time_slot_label' => $appointment->preferred_time_slot?->label(),
            'assignee_name' => $incident->assignee?->firstName() ?: $incident->assignee?->name,
        ];
    }
}
