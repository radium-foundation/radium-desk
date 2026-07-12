<?php

namespace App\Services\Automation;

use App\Models\Incident;
use App\Models\IncidentWaitingState;
use Illuminate\Support\Carbon;

/**
 * Meaningful customer progress that should stop waiting reminders / auto-close.
 *
 * Passive clicks, WhatsApp/email opens, and portal opens are intentionally excluded.
 */
class CustomerWaitingEngagementService
{
    public function hasEngagement(Incident $incident, ?IncidentWaitingState $waitingState = null): bool
    {
        return $this->hasActiveSupportAppointment($incident)
            || $this->hasSubmittedScheduleForm($incident, $waitingState)
            || $this->hasProvidedRequestedInformation($incident, $waitingState);
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

    /**
     * True when the customer provided the requested waiting input (e.g. serial).
     */
    public function hasProvidedRequestedInformation(
        Incident $incident,
        ?IncidentWaitingState $waitingState = null,
    ): bool {
        $since = $waitingState?->started_at;

        $incident->loadMissing('order');

        if ($incident->order === null || ! $incident->order->isSerialLocked()) {
            return false;
        }

        $serialEnteredAt = $incident->order->serial_entered_at;

        if ($since === null) {
            return true;
        }

        return $serialEnteredAt instanceof Carbon && $serialEnteredAt->gte($since);
    }

    /**
     * @deprecated Use hasProvidedRequestedInformation()
     */
    public function hasCustomerResponseOrAction(
        Incident $incident,
        ?IncidentWaitingState $waitingState = null,
    ): bool {
        return $this->hasProvidedRequestedInformation($incident, $waitingState);
    }
}
