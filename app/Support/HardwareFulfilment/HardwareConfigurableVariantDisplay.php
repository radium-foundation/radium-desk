<?php

namespace App\Support\HardwareFulfilment;

use App\Models\CommerceOrderItem;

/**
 * Deterministic hardware variant labels from stored commerce line FKs.
 * Does not query Box, inventory, or channel_sku_maps (no N+1).
 *
 * Mantra MFS option IDs were verified 2026-09-09 against radiumbox_prod.products:
 * models 945/946 (attribute 5), RD attribute 1, warranty attribute 2, OTG attribute 4.
 * USB+Type-C (996, 1127) is not a U/C code and is not formatted.
 */
final class HardwareConfigurableVariantDisplay
{
    public const FAMILY_MANTRA_MFS = 'Mantra MFS';

    /**
     * @var array<int, string>
     */
    private const MANTRA_MFS_MODELS = [
        945 => '100',
        946 => '110',
    ];

    /**
     * @var array<int, int>
     */
    private const MANTRA_MFS_RD_YEARS = [
        989 => 1,
        990 => 2,
        991 => 3,
        1119 => 1,
        1121 => 2,
        1122 => 1,
        1124 => 3,
    ];

    /**
     * @var array<int, int>
     */
    private const MANTRA_MFS_WARRANTY_YEARS = [
        992 => 1,
        993 => 2,
        994 => 3,
        1120 => 1,
        1123 => 2,
        1125 => 3,
    ];

    /**
     * @var array<int, string>
     */
    private const MANTRA_MFS_OTG = [
        995 => 'U',
        1126 => 'U',
        1724 => 'C',
    ];

    public static function format(string $family, string $model, int $rdYears, int $warrantyYears, string $otgCode): string
    {
        return $family.' '.$model.' '.$rdYears.'R '.$warrantyYears.'W '.$otgCode;
    }

    public static function forItem(CommerceOrderItem $item): ?string
    {
        $modelId = $item->model_id !== null ? (int) $item->model_id : 0;
        $model = self::MANTRA_MFS_MODELS[$modelId] ?? null;
        if ($model === null) {
            return null;
        }

        $rdId = $item->rdserviceid !== null ? (int) $item->rdserviceid : 0;
        $amcId = $item->amcid !== null ? (int) $item->amcid : 0;
        $otgId = $item->otgid !== null ? (int) $item->otgid : 0;

        $rd = self::MANTRA_MFS_RD_YEARS[$rdId] ?? null;
        $warranty = self::MANTRA_MFS_WARRANTY_YEARS[$amcId] ?? null;
        $otg = self::MANTRA_MFS_OTG[$otgId] ?? null;

        if ($rd === null || $warranty === null || $otg === null) {
            return null;
        }

        return self::format(self::FAMILY_MANTRA_MFS, $model, $rd, $warranty, $otg);
    }

    public static function label(CommerceOrderItem $item): string
    {
        $canonical = self::forItem($item);
        if ($canonical !== null) {
            return $canonical;
        }

        return self::fallbackLabel($item);
    }

    public static function invoiceDescription(CommerceOrderItem $item, bool $annotateBundledRd = false): string
    {
        $canonical = self::forItem($item);
        if ($canonical !== null) {
            return $canonical;
        }

        $description = self::fallbackLabel($item);
        if ($annotateBundledRd && $item->rdserviceid !== null) {
            $description .= ' (bundled RD #'.$item->rdserviceid.')';
        }

        return $description;
    }

    private static function fallbackLabel(CommerceOrderItem $item): string
    {
        $label = trim((string) $item->description);
        if ($label !== '') {
            return $label;
        }

        return trim((string) ($item->sku ?: $item->catalog_sku ?: ''));
    }
}
