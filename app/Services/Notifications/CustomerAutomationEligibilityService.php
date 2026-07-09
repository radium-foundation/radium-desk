<?php

namespace App\Services\Notifications;

use App\Models\Incident;
use App\Support\BonvoiceCallStatuses;

/**
 * Guards automated customer WhatsApp/email for enquiry/spam cases.
 *
 * Manual customer communication paths are not blocked by this service.
 */
class CustomerAutomationEligibilityService
{
    public function allowsAutomatedCustomerNotification(Incident $incident): bool
    {
        return $this->blockReason($incident) === null;
    }

    public function blockReason(Incident $incident): ?string
    {
        $incident->loadMissing(['order', 'bonvoiceCallLinks.bonvoiceCallEvent']);

        $order = $incident->order;

        if ($order === null) {
            return 'missing_order';
        }

        if (! $order->isInquiryOrder()) {
            return null;
        }

        if ($this->hasNoInputBonvoiceLink($incident)) {
            return 'noinput_spam_enquiry';
        }

        // Pure INQ recovery / enquiry without a verified RD/RDE customer order.
        return 'unverified_inquiry_recovery';
    }

    private function hasNoInputBonvoiceLink(Incident $incident): bool
    {
        foreach ($incident->bonvoiceCallLinks as $link) {
            $status = BonvoiceCallStatuses::normalize($link->bonvoiceCallEvent?->status);

            if ($status === 'NOINPUT') {
                return true;
            }
        }

        return false;
    }
}
