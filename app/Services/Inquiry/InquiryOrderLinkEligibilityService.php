<?php

namespace App\Services\Inquiry;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\User;

class InquiryOrderLinkEligibilityService
{
    public function canShowAction(Incident $incident, User $user): bool
    {
        if (! $user->can('update', $incident)) {
            return false;
        }

        $incident->loadMissing('order');

        if ($incident->order === null || ! $incident->order->isInquiryOrder()) {
            return false;
        }

        if ($incident->inquiry_origin_order_id !== null) {
            return false;
        }

        return in_array($incident->status, IncidentStatus::operationallyActive(), true);
    }
}
