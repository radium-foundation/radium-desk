<?php

namespace App\Support;

class DeviceModelFormatter
{
    public static function shortDisplay(?string $fullModel): ?string
    {
        if (! filled($fullModel)) {
            return null;
        }

        $fullModel = trim($fullModel);
        $tokens = preg_split('/\s+/', $fullModel) ?: [];

        if ($tokens === []) {
            return null;
        }

        if (count($tokens) === 1) {
            if (preg_match('/^([A-Za-z]+)(\d+)$/', $tokens[0], $matches)) {
                return $matches[1].' '.$matches[2];
            }

            return $tokens[0];
        }

        $first = $tokens[0];

        foreach (array_slice($tokens, 1) as $token) {
            if (preg_match('/^E\d+$/i', $token)) {
                return $first.' '.$token;
            }
        }

        if (preg_match('/^\d+$/', $tokens[1])) {
            return $first.' '.$tokens[1];
        }

        if (count($tokens) === 2) {
            return $first.' '.$tokens[1];
        }

        return $first;
    }
}
