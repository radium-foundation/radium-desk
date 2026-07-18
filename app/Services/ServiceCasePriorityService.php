<?php

namespace App\Services;

use App\Enums\IntakeChannel;
use App\Models\Incident;
use App\Models\User;

/**
 * Shared inbound-channel priority rules (IVR / Email / future channels).
 * Encodes the existing missed-call recovery boost: RD orders become high priority; INQ does not.
 */
class ServiceCasePriorityService
{
    public function applyInboundLinkBoost(Incident $incident, IntakeChannel $channel, User $actor): Incident
    {
        $incident->loadMissing('order');

        if ($incident->order?->isInquiryOrder()) {
            return $incident;
        }

        if ($incident->high_priority) {
            return $incident;
        }

        $incident->update([
            'high_priority' => true,
            'updated_by' => $actor->id,
        ]);

        return $incident->fresh(['order', 'assignee']);
    }
}
