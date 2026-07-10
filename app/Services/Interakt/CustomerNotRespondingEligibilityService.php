<?php

namespace App\Services\Interakt;

use App\Enums\IncidentStatus;
use App\Enums\WaitingReason;
use App\Enums\WhatsAppTemplate;
use App\Models\Incident;
use App\Services\Customer360\CustomerContactAttemptEvidenceService;
use App\Services\IncidentWaitingStateService;

class CustomerNotRespondingEligibilityService
{
    public function __construct(
        private readonly IncidentWaitingStateService $waitingStateService,
        private readonly CustomerContactAttemptEvidenceService $contactAttemptEvidenceService,
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
        if (! $this->isEligible($incident)) {
            return false;
        }

        $incident->loadMissing('activeWaitingState');

        if ($incident->assigned_to_user_id === null) {
            return false;
        }

        if ($this->waitingStateService->activeFor($incident) !== null) {
            return false;
        }

        if (! $this->contactAttemptEvidenceService->hasEvidenceFor($incident)) {
            return false;
        }

        return filled(config('interakt.templates.'.WhatsAppTemplate::CallbackSchedule->value.'.name'));
    }
}
