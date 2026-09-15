<?php

namespace App\Support\StatutoryInvoice;

final class StatutoryInvoiceNumber
{
    public const PATTERN = '/^INV-\d{2,}$/';

    public static function normalize(string $identifier): string
    {
        return strtoupper(trim($identifier));
    }

    public static function looksLike(string $identifier): bool
    {
        return preg_match(self::PATTERN, self::normalize($identifier)) === 1;
    }
}
