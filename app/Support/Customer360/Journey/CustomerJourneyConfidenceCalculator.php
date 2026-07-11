<?php

namespace App\Support\Customer360\Journey;

use App\Data\AI\CustomerJourneyBuildContext;
use App\Data\AI\CustomerJourneyConfidenceDTO;
use App\Data\AI\CustomerJourneyMilestoneDTO;
use App\Enums\AI\AIConfidenceLevel;
use App\Enums\AI\CustomerJourneyMilestoneType;
use App\Enums\SupportAppointmentStatus;
use Illuminate\Support\Str;

class CustomerJourneyConfidenceCalculator
{
    /**
     * @param  list<CustomerJourneyMilestoneDTO>  $milestones
     */
    public function calculate(CustomerJourneyBuildContext $context, array $milestones): CustomerJourneyConfidenceDTO
    {
        $score = 35;
        $positive = [];
        $negative = [];

        if ($this->hasMilestone($milestones, CustomerJourneyMilestoneType::PaymentReceived)) {
            $score += 15;
            $positive[] = 'Payment confirmed';
        }

        if ($this->hasMilestone($milestones, CustomerJourneyMilestoneType::DeviceIdentified)
            && filled($context->deviceModel)) {
            $score += 10;
            $positive[] = 'Device identified';
        } elseif (! filled($context->deviceModel)) {
            $score -= 10;
            $negative[] = 'Unknown device';
        }

        if ($this->hasMilestone($milestones, CustomerJourneyMilestoneType::SerialVerified)) {
            $score += 20;
            $positive[] = 'Serial verified';
        }

        if ($context->serialMissing) {
            $score -= 15;
            $negative[] = 'Missing serial';
        }

        if ($this->hasMilestone($milestones, CustomerJourneyMilestoneType::SupportCompleted)) {
            $score += 20;
            $positive[] = 'Appointment completed';
        }

        if ($this->hasMilestone($milestones, CustomerJourneyMilestoneType::CustomerReplied)) {
            $score += 10;
            $positive[] = 'Customer responded';
        }

        if ($this->hasCancelledAppointment($context, $milestones)) {
            $score -= 15;
            $negative[] = 'Cancelled appointment';
        }

        $reopenCount = $this->milestoneCount($milestones, CustomerJourneyMilestoneType::Reopened);

        if ($reopenCount > 1) {
            $score -= 10;
            $negative[] = 'Repeated reopen';
        }

        $score = min(100, max(0, $score));

        return new CustomerJourneyConfidenceDTO(
            score: $score,
            level: match (true) {
                $score >= 75 => AIConfidenceLevel::High,
                $score >= 45 => AIConfidenceLevel::Medium,
                default => AIConfidenceLevel::Low,
            },
            positiveSignals: $positive,
            negativeSignals: $negative,
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
    private function milestoneCount(array $milestones, CustomerJourneyMilestoneType $type): int
    {
        return count(array_filter(
            $milestones,
            fn (CustomerJourneyMilestoneDTO $milestone): bool => $milestone->type === $type,
        ));
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
}
