<?php

namespace App\Support\Customer360;

use App\Enums\CommunicationActionKey;
use App\Models\Incident;

/**
 * Inquiry-only cases must not surface marketplace purchase communication actions in Customer 360.
 */
final class InquiryCommercialPurchaseActionPolicy
{
    public static function hidesFromCustomerSurfaces(Incident $incident, CommunicationActionKey $key): bool
    {
        if (! $incident->order?->isInquiryOrder()) {
            return false;
        }

        return in_array($key, [
            CommunicationActionKey::BuyRdService,
            CommunicationActionKey::BuyProduct,
        ], true);
    }
}
