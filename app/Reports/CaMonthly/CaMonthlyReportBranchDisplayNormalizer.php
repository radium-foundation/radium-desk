<?php

namespace App\Reports\CaMonthly;

/**
 * CA-facing branch display normalization.
 *
 * Does not mutate source/master data; maps known branch codes and names to
 * human-readable labels for the statutory register export only.
 */
final class CaMonthlyReportBranchDisplayNormalizer
{
    /**
     * @var array<string, string>
     */
    private const CODE_ALIASES = [
        'radium_delhi' => 'Delhi',
        'delhi-retail' => 'Delhi',
        'delhi_retail' => 'Delhi',
        'delhi' => 'Delhi',
        'mumbai' => 'Mumbai',
    ];

    /**
     * @var array<string, string>
     */
    private const NAME_ALIASES = [
        'delhi retail' => 'Delhi',
        'radium delhi' => 'Delhi',
        'mumbai' => 'Mumbai',
    ];

    public static function normalize(mixed $value): string
    {
        $trimmed = self::nullableString($value);
        if ($trimmed === null) {
            return '';
        }

        $codeKey = strtolower(str_replace([' ', '_'], ['-', '-'], $trimmed));
        if (isset(self::CODE_ALIASES[$codeKey])) {
            return self::CODE_ALIASES[$codeKey];
        }

        $nameKey = strtolower(str_replace(['_', '-'], ' ', $trimmed));
        if (isset(self::NAME_ALIASES[$nameKey])) {
            return self::NAME_ALIASES[$nameKey];
        }

        return $trimmed;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
