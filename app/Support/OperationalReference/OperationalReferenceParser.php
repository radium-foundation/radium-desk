<?php

namespace App\Support\OperationalReference;

/**
 * Parsing rules for independent operational reference series.
 *
 * Historical formats are preserved in the database and excluded from new-series
 * max calculations. Statutory invoice numbering (INV-*) is a separate system.
 */
final class OperationalReferenceParser
{
    public const REFUND_FLOOR = 67315;

    public const SERVICE_ORDER_FLOOR = 671;

    public const PRODUCT_POS_FLOOR = 6720;

    /**
     * New refund format: REF-{integer} (e.g. REF-67315).
     * Legacy year format REF-YYYY-NNNNNN is ignored for sequence advancement.
     */
    public static function parseRefundOperationalValue(string $reference): ?int
    {
        if (preg_match('/^REF-(\d{4})-\d{6}$/', $reference) === 1) {
            return null;
        }

        if (preg_match('/^REF-(\d+)$/', $reference, $matches) !== 1) {
            return null;
        }

        $value = (int) $matches[1];

        return $value >= self::REFUND_FLOOR ? $value : null;
    }

    /**
     * New service order format: SVC-{integer} without zero padding (e.g. SVC-671).
     * Legacy ID-derived values such as SVC-000001 are ignored.
     */
    public static function parseServiceOrderOperationalValue(string $reference): ?int
    {
        if (preg_match('/^SVC-0+\d+$/', $reference) === 1) {
            return null;
        }

        if (preg_match('/^SVC-(\d+)$/', $reference, $matches) !== 1) {
            return null;
        }

        $value = (int) $matches[1];

        return $value >= self::SERVICE_ORDER_FLOOR ? $value : null;
    }

    /**
     * New Product POS format: POS-{integer} without zero padding (e.g. POS-6720).
     * Legacy ID-derived values such as POS-000019 are ignored.
     */
    public static function parseProductPosOperationalValue(string $reference): ?int
    {
        if (preg_match('/^POS-0+\d+$/', $reference) === 1) {
            return null;
        }

        if (preg_match('/^POS-(\d+)$/', $reference, $matches) !== 1) {
            return null;
        }

        $value = (int) $matches[1];

        return $value >= self::PRODUCT_POS_FLOOR ? $value : null;
    }

    public static function isLegacyRefundReference(string $reference): bool
    {
        return preg_match('/^REF-\d{4}-\d{6}$/', $reference) === 1;
    }

    public static function isLegacyServiceOrderReference(string $reference): bool
    {
        return preg_match('/^SVC-0+\d+$/', $reference) === 1;
    }

    public static function isLegacyProductPosReference(string $reference): bool
    {
        return preg_match('/^POS-0+\d+$/', $reference) === 1;
    }
}
