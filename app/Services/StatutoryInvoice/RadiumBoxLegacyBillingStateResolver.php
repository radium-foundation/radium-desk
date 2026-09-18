<?php

namespace App\Services\StatutoryInvoice;

use App\Support\Finance\IndianStates;

/**
 * Maps legacy RadiumBox billing state labels to current Indian GST state names.
 */
final class RadiumBoxLegacyBillingStateResolver
{
    /** @var array<string, string> */
    private const LEGACY_ALIASES = [
        'Dadra and Nagar Haveli' => 'Dadra and Nagar Haveli and Daman and Diu',
        'Daman and Diu' => 'Dadra and Nagar Haveli and Daman and Diu',
    ];

    public static function resolve(?string $state): ?string
    {
        if ($state === null) {
            return null;
        }

        $trimmed = trim($state);
        if ($trimmed === '') {
            return null;
        }

        if (IndianStates::contains($trimmed)) {
            return $trimmed;
        }

        return self::LEGACY_ALIASES[$trimmed] ?? $trimmed;
    }
}
