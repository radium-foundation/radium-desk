<?php

namespace App\Support\Customer360\Journey;

use App\Data\AI\CustomerJourneyBuildContext;
use App\Data\AI\CustomerJourneyConclusionDTO;
use App\Data\AI\CustomerJourneyMilestoneDTO;
use App\Enums\AI\CustomerJourneyConclusionType;
use App\Enums\AI\CustomerJourneyMilestoneType;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentStatus;
use Illuminate\Support\Str;

class CustomerJourneyConclusionResolver
{
    /**
     * @param  list<CustomerJourneyMilestoneDTO>  $milestones
     */
    public function resolve(CustomerJourneyBuildContext $context, array $milestones): CustomerJourneyConclusionDTO
    {
        $hasSupportCompleted = $this->hasMilestone($milestones, CustomerJourneyMilestoneType::SupportCompleted);
        $hasClosed = $this->hasMilestone($milestones, CustomerJourneyMilestoneType::Closed)
            || $context->incident->status === IncidentStatus::Closed;
        $hasReopened = $this->hasMilestone($milestones, CustomerJourneyMilestoneType::Reopened);
        $hasCancelledAppointment = $this->hasCancelledAppointment($context, $milestones);
        $hasActiveWaiting = $this->hasActiveWaiting($milestones, $context);
        $isBlockedBySerial = $context->serialMissing || $this->hasActiveSerialBlock($milestones);

        if ($hasReopened && $hasSupportCompleted) {
            return new CustomerJourneyConclusionDTO(
                type: CustomerJourneyConclusionType::Reopened,
                headline: CustomerJourneyConclusionType::Reopened->label(),
                detail: 'Previous support completed. Issue has reoccurred.',
                recommendation: 'Recommend engineer review and proactive customer callback.',
            );
        }

        if ($hasCancelledAppointment && ! $hasSupportCompleted) {
            return new CustomerJourneyConclusionDTO(
                type: CustomerJourneyConclusionType::Interrupted,
                headline: CustomerJourneyConclusionType::Interrupted->label(),
                detail: 'Customer booked support but appointment was cancelled.',
                recommendation: 'Recommend rebooking support with the customer.',
            );
        }

        if ($isBlockedBySerial || $hasActiveWaiting) {
            $waitingLabel = $this->waitingLabel($milestones, $context);

            return new CustomerJourneyConclusionDTO(
                type: CustomerJourneyConclusionType::Blocked,
                headline: CustomerJourneyConclusionType::Blocked->label(),
                detail: $waitingLabel,
                recommendation: 'Customer reminder still required.',
            );
        }

        if ($hasClosed && $hasSupportCompleted) {
            return new CustomerJourneyConclusionDTO(
                type: CustomerJourneyConclusionType::Complete,
                headline: CustomerJourneyConclusionType::Complete->label(),
                detail: 'Customer already received support.',
                recommendation: 'No customer reminder required.',
            );
        }

        if ($hasClosed) {
            return new CustomerJourneyConclusionDTO(
                type: CustomerJourneyConclusionType::Complete,
                headline: CustomerJourneyConclusionType::Complete->label(),
                detail: 'Service case is closed.',
                recommendation: 'Reopen only if the customer reports a new issue.',
            );
        }

        if ($context->supportAppointment !== null
            && ($context->supportAppointment['is_active'] ?? false)) {
            return new CustomerJourneyConclusionDTO(
                type: CustomerJourneyConclusionType::InProgress,
                headline: CustomerJourneyConclusionType::InProgress->label(),
                detail: 'Await scheduled support.',
                recommendation: 'Assigned agent should contact the customer as scheduled.',
            );
        }

        return new CustomerJourneyConclusionDTO(
            type: CustomerJourneyConclusionType::InProgress,
            headline: CustomerJourneyConclusionType::InProgress->label(),
            detail: 'Service case is progressing through standard handling.',
            recommendation: 'Review incident details and contact the customer with the next update.',
        );
    }

    /**
     * @param  list<CustomerJourneyMilestoneDTO>  $milestones
     */
    private function hasMilestone(array $milestones, CustomerJourneyMilestoneType $type): bool
    {
        foreach ($milestones as $milestone) {
            if ($milestone->type === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<CustomerJourneyMilestoneDTO>  $milestones
     */
    private function hasCancelledAppointment(CustomerJourneyBuildContext $context, array $milestones): bool
    {
        $appointment = $context->supportAppointment;

        if (is_array($appointment) && ($appointment['status'] ?? null) === SupportAppointmentStatus::Cancelled) {
            return true;
        }

        foreach ($milestones as $milestone) {
            if ($milestone->type === CustomerJourneyMilestoneType::SupportAppointmentBooked
                && $milestone->status === 'cancelled') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<CustomerJourneyMilestoneDTO>  $milestones
     */
    private function hasActiveWaiting(array $milestones, CustomerJourneyBuildContext $context): bool
    {
        if ($context->incident->activeWaitingState !== null) {
            return true;
        }

        foreach ($milestones as $milestone) {
            if ($milestone->type === CustomerJourneyMilestoneType::WaitingForCustomer
                && $milestone->status === 'active') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<CustomerJourneyMilestoneDTO>  $milestones
     */
    private function hasActiveSerialBlock(array $milestones): bool
    {
        foreach ($milestones as $milestone) {
            if ($milestone->type === CustomerJourneyMilestoneType::SerialCorrectionRequested
                && $milestone->status === 'active') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<CustomerJourneyMilestoneDTO>  $milestones
     */
    private function waitingLabel(array $milestones, CustomerJourneyBuildContext $context): string
    {
        foreach ($milestones as $milestone) {
            if ($milestone->type === CustomerJourneyMilestoneType::WaitingForCustomer) {
                return Str::ucfirst($milestone->title).'.';
            }
        }

        if ($context->serialMissing) {
            return 'Waiting for serial.';
        }

        $reason = is_array($context->waitingState)
            ? ($context->waitingState['reason_label'] ?? 'customer input')
            : 'customer input';

        return 'Waiting for '.$reason.'.';
    }
}
