<?php

namespace App\Support;

class LegacyOrderDisplay
{
    public static function formatAmcDetails(mixed $amcDetails): ?string
    {
        if ($amcDetails === null) {
            return null;
        }

        if (is_string($amcDetails)) {
            $trimmed = trim($amcDetails);

            if ($trimmed === '') {
                return null;
            }

            try {
                $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $trimmed;
            }

            if (is_array($decoded)) {
                return self::formatAmcDetails($decoded);
            }

            return $trimmed;
        }

        if (! is_array($amcDetails)) {
            return (string) $amcDetails;
        }

        if (filled($amcDetails['service_name'] ?? null)) {
            return (string) $amcDetails['service_name'];
        }

        $parts = [];

        foreach ($amcDetails as $key => $value) {
            if (! filled($value)) {
                continue;
            }

            if (is_scalar($value)) {
                $parts[] = (string) $value;

                continue;
            }

            if (is_array($value)) {
                $nested = self::formatAmcDetails($value);

                if (filled($nested)) {
                    $parts[] = $nested;
                }
            }
        }

        return $parts !== [] ? implode(', ', $parts) : null;
    }

    /**
     * @param  array<string, mixed>|null  $address
     */
    public static function formatDeliveryAddress(?array $address): ?string
    {
        if ($address === null || $address === []) {
            return null;
        }

        $lines = [];

        if (filled($address['line'] ?? null)) {
            $lines[] = (string) $address['line'];
        }

        if (filled($address['district'] ?? null)) {
            $lines[] = (string) $address['district'];
        }

        if (filled($address['state'] ?? null)) {
            $lines[] = (string) $address['state'];
        }

        if (filled($address['pincode'] ?? null)) {
            $pin = (string) $address['pincode'];

            if (($address['pincode_profile_mismatch'] ?? false) === true) {
                $pin .= ' (order checkout; customer profile PIN differs)';
            }

            $lines[] = 'PIN '.$pin;
        }

        return $lines !== [] ? implode("\n", $lines) : null;
    }

    public static function formatInrAmount(?string $amount): ?string
    {
        if ($amount === null || trim($amount) === '') {
            return null;
        }

        $normalized = trim($amount);

        if (! is_numeric($normalized)) {
            return '₹'.$normalized;
        }

        $value = (float) $normalized;

        if (floor($value) === $value) {
            return '₹'.(string) (int) $value;
        }

        return '₹'.rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
