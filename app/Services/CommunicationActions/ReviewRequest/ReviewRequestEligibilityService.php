<?php

namespace App\Services\CommunicationActions\ReviewRequest;

use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;

class ReviewRequestEligibilityService
{
    public function ineligibilityReason(Incident $incident): ?string
    {
        $incident->loadMissing(['order', 'supportAppointments']);
        $order = $incident->order;

        if ($order === null) {
            return 'Link an order before sending a review request.';
        }

        if (! $this->hasCustomerContact($order)) {
            return 'Customer contact details are required before sending a review request.';
        }

        if (! $this->isEligibleForReviewRequest($incident)) {
            return 'Review requests can be sent after support work is completed or the service case is resolved.';
        }

        return null;
    }

    private function isEligibleForReviewRequest(Incident $incident): bool
    {
        if (in_array($incident->status, [IncidentStatus::Resolved, IncidentStatus::Closed], true)) {
            return true;
        }

        return $this->hasCompletedSupportWork($incident);
    }

    private function hasCompletedSupportWork(Incident $incident): bool
    {
        if ($incident->relationLoaded('supportAppointments')) {
            return $incident->supportAppointments->contains(
                fn (SupportAppointment $appointment): bool => $appointment->status === SupportAppointmentStatus::Completed,
            );
        }

        return $incident->supportAppointments()
            ->where('status', SupportAppointmentStatus::Completed)
            ->exists();
    }

    private function hasCustomerContact(Order $order): bool
    {
        $phone = trim((string) ($order->customer_phone ?? ''));
        $email = trim((string) ($order->customer_email ?? ''));

        if ($phone !== '') {
            return true;
        }

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
