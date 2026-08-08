<?php

namespace App\Support\Bonvoice;

class BonvoicePhoneNormalizer
{
    public static function normalizeDialablePhone(?string $phone): ?string
    {
        if (! filled($phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 13 && str_starts_with($digits, '910')) {
            $digits = substr($digits, 3);
        }

        if (strlen($digits) !== 10) {
            return null;
        }

        return $digits;
    }
}
