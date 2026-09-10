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
}
