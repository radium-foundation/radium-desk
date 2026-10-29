<?php

namespace App\Support\StatutoryInvoice;

/**
 * Sale/order-time structured billing snapshot. Does not parse free-text addresses.
 *
 * @phpstan-type StructuredAddress array{line1?: string, line2?: string, city?: string, state?: string, pincode?: string}
 */
final class StatutoryBillingStructured
{
    /**
     * @return StructuredAddress|null
     */
    public static function fromParts(
        ?string $line1,
        ?string $city,
        ?string $state,
        ?string $pincode,
        ?string $line2 = null,
    ): ?array {
        $out = [];
        $line1 = self::nullable($line1);
        $line2 = self::nullable($line2);
        $city = self::nullable($city);
        $state = self::nullable($state);
        $pin = self::pin($pincode);

        if ($line1 !== null) {
            $out['line1'] = $line1;
        }
        if ($line2 !== null) {
            $out['line2'] = $line2;
        }
        if ($city !== null) {
            $out['city'] = $city;
        }
        if ($state !== null) {
            $out['state'] = $state;
        }
        if ($pin !== null) {
            $out['pincode'] = $pin;
        }

        return $out === [] ? null : $out;
    }

    /**
     * @param  array<string, mixed>|null  $structured
     */
    public static function isCompleteForIrn(?array $structured): bool
    {
        if ($structured === null) {
            return false;
        }

        return self::nullable($structured['city'] ?? null) !== null
            && self::nullable($structured['state'] ?? null) !== null
            && self::pin($structured['pincode'] ?? null) !== null;
    }

    /**
     * @param  array<string, mixed>|null  $structured
     * @return StructuredAddress|null
     */
    public static function fromStored(mixed $structured): ?array
    {
        if (is_string($structured)) {
            $decoded = json_decode($structured, true);
            $structured = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($structured)) {
            return null;
        }

        return self::fromParts(
            self::nullable($structured['line1'] ?? null),
            self::nullable($structured['city'] ?? null),
            self::nullable($structured['state'] ?? null),
            self::nullable($structured['pincode'] ?? null),
            self::nullable($structured['line2'] ?? null),
        );
    }

    public static function pin(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if (! preg_match('/^[1-9][0-9]{5}$/', $digits)) {
            return null;
        }

        return $digits;
    }

    public static function nullable(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * City/state/PIN line for statutory PDF bill-to when structured snapshot parts
     * are not already represented in the free-text billing address.
     */
    public static function pdfLocalityLine(mixed $structured, ?string $billingAddress): ?string
    {
        $structured = self::fromStored($structured);
        if ($structured === null) {
            return null;
        }

        $city = self::nullable($structured['city'] ?? null);
        $state = self::nullable($structured['state'] ?? null);
        $pin = self::pin($structured['pincode'] ?? null);

        if ($city === null && $state === null && $pin === null) {
            return null;
        }

        $haystack = self::normalizedAddressHaystack($billingAddress);
        $segments = [];

        if ($city !== null && ! self::componentPresentInAddress($city, $haystack)) {
            $segments[] = $city;
        }

        $statePin = self::statePinSegment($state, $pin, $haystack);
        if ($statePin !== null) {
            $segments[] = $statePin;
        } elseif ($pin !== null && ! self::componentPresentInAddress($pin, $haystack)) {
            $segments[] = $pin;
        }

        if ($segments === []) {
            return null;
        }

        return implode(', ', $segments);
    }

    private static function statePinSegment(?string $state, ?string $pin, string $haystack): ?string
    {
        $statePresent = $state !== null && self::componentPresentInAddress($state, $haystack);
        $pinPresent = $pin !== null && self::componentPresentInAddress($pin, $haystack);

        if ($statePresent && $pinPresent) {
            return null;
        }

        if ($state !== null && $pin !== null) {
            if ($statePresent) {
                return $pinPresent ? null : $pin;
            }
            if ($pinPresent) {
                return $state;
            }

            return $state.' '.$pin;
        }

        if ($state !== null && ! $statePresent) {
            return $state;
        }

        return null;
    }

    private static function normalizedAddressHaystack(?string $billingAddress): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim((string) $billingAddress)) ?? '';

        return strtolower($normalized);
    }

    private static function componentPresentInAddress(string $component, string $haystack): bool
    {
        $needle = strtolower(trim($component));
        if ($needle === '') {
            return true;
        }

        return $haystack !== '' && str_contains($haystack, $needle);
    }
}
