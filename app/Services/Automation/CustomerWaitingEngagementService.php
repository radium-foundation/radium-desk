<?php

namespace App\Services\Automation;

use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\NotificationLinkClick;
use Illuminate\Support\Carbon;

class CustomerWaitingEngagementService
{
    public function hasEngagement(Incident $incident, ?IncidentWaitingState $waitingState = null): bool
    {
        return $this->hasActiveSupportAppointment($incident)
            || $this->hasSubmittedScheduleForm($incident, $waitingState)
            || $this->hasCustomerResponseOrAction($incident, $waitingState);
    }

    public function hasActiveSupportAppointment(Incident $incident): bool
    {
        return $incident->hasActiveSupportAppointment();
    }

    public function hasSubmittedScheduleForm(Incident $incident, ?IncidentWaitingState $waitingState = null): bool
    {
        $query = $incident->supportAppointments();

        if ($waitingState?->started_at !== null) {
            $query->where('created_at', '>=', $waitingState->started_at);
        }

        return $query->exists();
    }

    public function hasCustomerResponseOrAction(Incident $incident, ?IncidentWaitingState $waitingState = null): bool
    {
        $since = $waitingState?->started_at;

        $incident->loadMissing('order');

        if ($incident->order !== null && $incident->order->isSerialLocked()) {
            $serialEnteredAt = $incident->order->serial_entered_at;

            if ($since === null || ($serialEnteredAt instanceof Carbon && $serialEnteredAt->gte($since))) {
                return true;
            }
        }

        $clickQuery = NotificationLinkClick::query()->where('incident_id', $incident->id);

        if ($since !== null) {
            $clickQuery->where('clicked_at', '>=', $since);
        }

        return $clickQuery->exists();
    }
}
