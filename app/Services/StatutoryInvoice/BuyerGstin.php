<?php

namespace App\Services\StatutoryInvoice;

final class BuyerGstin
{
    public static function normalize(?string $gstin): ?string
    {
        if ($gstin === null) {
            return null;
        }

        $value = strtoupper(preg_replace('/\s+/', '', trim($gstin)) ?? '');

        return $value === '' ? null : $value;
    }

    public static function isValid(?string $gstin): bool
    {
        $value = self::normalize($gstin);
        if ($value === null) {
            return false;
        }

        return (bool) preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $value);
    }
}
