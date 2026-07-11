<?php

namespace App\Support\Customer360\Journey\Contributors;

use App\Contracts\AI\CustomerJourneyMilestoneContributor;
use App\Data\AI\CustomerJourneyBuildContext;
use App\Data\AI\CustomerJourneyMilestoneDTO;
use App\Enums\AI\CustomerJourneyMilestoneType;
use App\Enums\SupportAppointmentStatus;
use App\Models\SupportAppointment;
use Illuminate\Support\Carbon;

class SupportMilestoneContributor implements CustomerJourneyMilestoneContributor
{
    public function contribute(CustomerJourneyBuildContext $context): array
    {
        $context->incident->loadMissing('supportAppointments');

        $milestones = [];

        foreach ($context->incident->supportAppointments as $appointment) {
            if (! $appointment instanceof SupportAppointment) {
                continue;
            }

            $bookedAt = $appointment->created_at ?? $appointment->preferred_date ?? now();

            $milestones[] = new CustomerJourneyMilestoneDTO(
                type: CustomerJourneyMilestoneType::SupportAppointmentBooked,
                title: CustomerJourneyMilestoneType::SupportAppointmentBooked->label(),
                timestamp: $bookedAt instanceof Carbon ? $bookedAt : Carbon::parse($bookedAt),
                status: $this->appointmentStatus($appointment),
                actor: null,
                source: 'appointment',
                confidence: 95,
            );

            if ($appointment->status === SupportAppointmentStatus::Completed) {
                $completedAt = $appointment->updated_at ?? $bookedAt;

                $milestones[] = new CustomerJourneyMilestoneDTO(
                    type: CustomerJourneyMilestoneType::SupportCompleted,
                    title: CustomerJourneyMilestoneType::SupportCompleted->label(),
                    timestamp: $completedAt instanceof Carbon ? $completedAt : Carbon::parse($completedAt),
                    status: 'completed',
                    actor: null,
                    source: 'appointment',
                    confidence: 95,
                );
            }
        }

        return $milestones;
    }

    private function appointmentStatus(SupportAppointment $appointment): string
    {
        return match ($appointment->status) {
            SupportAppointmentStatus::Scheduled => 'active',
            SupportAppointmentStatus::Cancelled => 'cancelled',
            SupportAppointmentStatus::Completed => 'completed',
            default => 'completed',
        };
    }
}
