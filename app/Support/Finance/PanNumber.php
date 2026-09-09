<?php

namespace App\Support\Finance;

use App\Services\StatutoryInvoice\BuyerGstin;

final class PanNumber
{
    public static function normalize(?string $pan): ?string
    {
        if ($pan === null) {
            return null;
        }

        $value = strtoupper(preg_replace('/\s+/', '', trim($pan)) ?? '');

        return $value === '' ? null : $value;
    }

    public static function isValid(?string $pan): bool
    {
        $value = self::normalize($pan);
        if ($value === null) {
            return false;
        }

        return (bool) preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $value);
    }

    public static function fromGstin(?string $gstin): ?string
    {
        $gstin = BuyerGstin::normalize($gstin);
        if ($gstin === null || strlen($gstin) < 12) {
            return null;
        }

        $pan = substr($gstin, 2, 10);

        return self::isValid($pan) ? $pan : null;
    }
}
