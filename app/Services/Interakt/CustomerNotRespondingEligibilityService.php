<?php

namespace App\Services\Interakt;

use App\Enums\IncidentStatus;
use App\Enums\WaitingReason;
use App\Enums\WhatsAppTemplate;
use App\Models\Incident;
use App\Services\IncidentWaitingStateService;

class CustomerNotRespondingEligibilityService
{
    public function __construct(
        private readonly IncidentWaitingStateService $waitingStateService,
    ) {}

    public function isEligible(Incident $incident): bool
    {
        return $this->ineligibilityReason($incident) === null;
    }

    public function ineligibilityReason(Incident $incident): ?string
    {
        if (! in_array($incident->status, IncidentStatus::operationallyActive(), true)) {
            return 'Service case is not open for customer follow-up.';
        }

        $incident->loadMissing('order');
        $order = $incident->order;

        if ($order === null) {
            return 'Service case is not linked to an order.';
        }

        if (! filled($incident->reference_no)) {
            return 'Support reference is not available.';
        }

        $activeWaitingState = $this->waitingStateService->activeFor($incident);

        if ($activeWaitingState !== null) {
            if ($activeWaitingState->waiting_reason !== WaitingReason::CustomerNotResponding) {
                return 'Another customer waiting state is already active.';
            }

            if ($activeWaitingState->customer_followup_sent_at !== null) {
                return 'Customer callback schedule message was already sent.';
            }
        }

        if (! filled($order->customer_phone) && ! filled($order->customer_email)) {
            return 'Customer contact details are not available.';
        }

        return null;
    }

    public function canShowAction(Incident $incident): bool
    {
        return $this->isEligible($incident)
            && filled(config('interakt.templates.'.WhatsAppTemplate::CallbackSchedule->value.'.name'));
    }
}
