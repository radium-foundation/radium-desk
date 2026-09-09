<?php

namespace App\Support\Finance;

use App\Services\StatutoryInvoice\BuyerGstin;

final class PartyGstin
{
    public static function normalize(?string $gstin): ?string
    {
        return BuyerGstin::normalize($gstin);
    }

    public static function isValid(?string $gstin): bool
    {
        $value = self::normalize($gstin);
        if ($value === null || ! BuyerGstin::isValid($value)) {
            return false;
        }

        $stateCode = BuyerGstin::stateCode($value);

        return $stateCode !== null && GstStateCodes::isKnownCode($stateCode);
    }

    public static function stateCode(?string $gstin): ?string
    {
        if (! self::isValid($gstin)) {
            return null;
        }

        return BuyerGstin::stateCode($gstin);
    }

    public static function stateName(?string $gstin): ?string
    {
        return GstStateCodes::nameForCode(self::stateCode($gstin));
    }
}
