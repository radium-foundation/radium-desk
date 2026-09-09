<?php

namespace App\Services\Shipping\Data;

use App\Services\Shipping\ShiprocketAdhocAddressLimitException;

/**
 * Shiprocket create/adhoc only. Combined Address 1 + Address 2 cannot exceed
 * 190 UTF-8 characters. Never mutates stored customer/source addresses.
 */
final class ShiprocketAdhocAddressNormalizer
{
    public const COMBINED_MAX = 190;

    /**
     * @return array{0: string, 1: ?string}
     */
    public static function forPayload(string $line1, ?string $line2, string $state, string $pincode): array
    {
        if (self::combinedCharacterLength($line1, $line2) <= self::COMBINED_MAX) {
            return [$line1, $line2];
        }

        $normalizedLine1 = self::stripDuplicateStructuredSuffix($line1, $state, $pincode);
        $normalizedLine2 = $line2;

        if (self::combinedCharacterLength($normalizedLine1, $normalizedLine2) > self::COMBINED_MAX) {
            $strippedLine2 = self::stripDuplicateStructuredSuffix((string) $line2, $state, $pincode);
            $normalizedLine2 = $line2 === null ? null : $strippedLine2;
        }

        if (self::combinedCharacterLength($normalizedLine1, $normalizedLine2) <= self::COMBINED_MAX) {
            return [$normalizedLine1, $normalizedLine2];
        }

        throw new ShiprocketAdhocAddressLimitException(
            'The shipping address exceeds Shiprocket\'s combined Address 1 + Address 2 limit of 190 characters and requires review. Delivery-critical text was not truncated.',
        );
    }

    public static function combinedCharacterLength(string $line1, ?string $line2): int
    {
        return self::characterLength($line1) + self::characterLength((string) $line2);
    }

    public static function characterLength(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    private static function stripDuplicateStructuredSuffix(string $line, string $state, string $pincode): string
    {
        $state = trim($state);
        $digits = preg_replace('/\D+/', '', $pincode) ?? '';

        if ($line === '' || ($state === '' && $digits === '')) {
            return $line;
        }

        $statePattern = $state !== '' ? preg_quote($state, '/') : '';
        $pinPattern = $digits !== ''
            ? implode('\\s*', array_map(
                static fn (string $digit): string => preg_quote($digit, '/'),
                str_split($digits),
            ))
            : '';

        $leadingSeparator = '[\s,;:]+';
        $valueSeparator = '[\s,;:\-\x{2013}\x{2014}]+';
        $patterns = [];

        if ($statePattern !== '' && $pinPattern !== '') {
            $patterns[] = '/'.$leadingSeparator.$statePattern.$valueSeparator.$pinPattern.'\s*$/iu';
            $patterns[] = '/'.$leadingSeparator.$pinPattern.$valueSeparator.$statePattern.'\s*$/iu';
        }
        if ($pinPattern !== '') {
            $patterns[] = '/'.$leadingSeparator.$pinPattern.'\s*$/iu';
        }
        if ($statePattern !== '') {
            $patterns[] = '/'.$leadingSeparator.$statePattern.'\s*$/iu';
        }

        foreach ($patterns as $pattern) {
            $stripped = preg_replace($pattern, '', $line, 1);
            if (! is_string($stripped) || $stripped === $line) {
                continue;
            }

            $stripped = rtrim($stripped, " \t,;:-");
            if ($stripped === '') {
                continue;
            }

            return $stripped;
        }

        return $line;
    }
}
