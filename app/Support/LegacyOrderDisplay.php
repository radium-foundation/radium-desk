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
}
