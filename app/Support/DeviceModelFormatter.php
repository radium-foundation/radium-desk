<?php

namespace App\Support;

class DeviceModelFormatter
{
    /** @var list<string> Leading vendor/marketing words stripped before parsing. */
    private const MARKETING_PREFIXES = [
        'ACCESS',
    ];

    /** @var list<string> Trailing product-line, install, or location tokens stripped after parsing. */
    private const IGNORABLE_SUFFIXES = [
        'IRIS',
        'U',
        'RD',
        'L1',
    ];

    public static function shortDisplay(?string $fullModel): ?string
    {
        if (! filled($fullModel)) {
            return null;
        }

        $tokens = self::normalizeTokens(trim($fullModel));

        if ($tokens === []) {
            return null;
        }

        $prefix = $tokens[0];

        foreach (array_slice($tokens, 1) as $token) {
            if (self::isVariantCode($token)) {
                return $prefix.' '.$token;
            }
        }

        if (isset($tokens[1]) && preg_match('/^\d+$/', $tokens[1])) {
            return $prefix.' '.$tokens[1];
        }

        if (count($tokens) === 2 && ! self::isIgnorableSuffix($tokens[1])) {
            return $prefix.' '.$tokens[1];
        }

        return $prefix;
    }

    /**
     * @return list<string>
     */
    private static function normalizeTokens(string $fullModel): array
    {
        $tokens = preg_split('/\s+/', $fullModel) ?: [];

        while ($tokens !== [] && self::isMarketingPrefix($tokens[0])) {
            array_shift($tokens);
        }

        $normalized = [];

        foreach ($tokens as $token) {
            foreach (self::expandToken($token) as $part) {
                $normalized[] = $part;
            }
        }

        while (count($normalized) > 1 && self::isIgnorableSuffix($normalized[array_key_last($normalized)])) {
            array_pop($normalized);
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private static function expandToken(string $token): array
    {
        if (preg_match('/^([A-Za-z]{2,})(\d{2,})([A-Za-z]*)$/', $token, $matches)) {
            $parts = [$matches[1], $matches[2]];

            if ($matches[3] !== '') {
                $parts[] = $matches[3];
            }

            return $parts;
        }

        return [$token];
    }

    private static function isMarketingPrefix(string $token): bool
    {
        return in_array(strtoupper($token), self::MARKETING_PREFIXES, true);
    }

    private static function isIgnorableSuffix(string $token): bool
    {
        return in_array(strtoupper($token), self::IGNORABLE_SUFFIXES, true);
    }

    private static function isVariantCode(string $token): bool
    {
        return (bool) preg_match('/^E\d+$/i', $token);
    }
}
