<?php

namespace App\Support\HardwareFulfilment;

use App\Models\CommerceOrderItem;

/**
 * Deterministic hardware variant labels from stored commerce line FKs.
 * Does not query Box, inventory, or channel_sku_maps (no N+1).
 *
 * Mantra MFS option IDs were verified 2026-09-09 against radiumbox_prod.products:
 * models 945/946 (attribute 5), RD attribute 1, warranty attribute 2, OTG attribute 4.
 * Replacement cable model IDs verified 2026-09-18 against radiumbox_prod.products parent 1408.
 */
final class HardwareConfigurableVariantDisplay
{
    public const FAMILY_MANTRA_MFS = 'Mantra MFS';

    /**
     * Verified Box replacement-cable model_ids (parent product 1408).
     *
     * @var array<int, true>
     */
    private const REPLACEMENT_CABLE_MODEL_IDS = [
        1409 => true,
        1410 => true,
        1419 => true,
        1420 => true,
        1421 => true,
        1422 => true,
    ];

    /**
     * Verified Box model_id → operator-facing variant label (P0-M1 / investigation).
     *
     * @var array<int, string>
     */
    private const STATIC_VARIANT_LABELS = [
        347 => 'Feitian ePass HYP2003 Auto USB Token',
        1749 => 'BioEnable C600 Face Camera · C600',
        340 => 'Dell WM112 Wireless Optical Mouse (Black)',
        1409 => 'Mantra MFS110 Type-C',
        1410 => 'Mantra MFS110 USB',
        1419 => 'Morpho Type-C',
        1420 => 'Morpho USB',
        1421 => 'Access FM220 Type-C',
        1422 => 'Access FM220 USB',
        1514 => 'USB to Type C Connector - pack of 10',
    ];

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

        $modelId = $item->model_id !== null ? (int) $item->model_id : 0;
        $static = self::STATIC_VARIANT_LABELS[$modelId] ?? null;
        if ($static !== null) {
            return $static;
        }

        $variant = trim((string) ($item->variant ?? ''));
        if ($variant !== '') {
            return $variant;
        }

        if (isset(self::MANTRA_MFS_MODELS[$modelId])) {
            return self::fallbackLabel($item);
        }

        $fallback = self::fallbackLabel($item);
        if (self::isAmbiguousMarketingDescription($fallback)) {
            return 'Exact variant unavailable';
        }

        return $fallback;
    }

    /**
     * Operator-facing fulfilment label (Ready Queue, hardware workspace).
     * Appends an explicit Cable designation for verified replacement-cable model_ids.
     */
    public static function operatorLabel(CommerceOrderItem $item): string
    {
        $modelId = $item->model_id !== null ? (int) $item->model_id : 0;

        return self::withReplacementCableDesignation($modelId, self::label($item));
    }

    public static function invoiceDescription(CommerceOrderItem $item, bool $annotateBundledRd = false): string
    {
        $description = self::label($item);
        if ($description === 'Exact variant unavailable') {
            $description = self::fallbackLabel($item);
        }

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

    private static function isAmbiguousMarketingDescription(string $label): bool
    {
        if (strcasecmp(trim($label), 'Biometric Replacement Cable') === 0) {
            return true;
        }

        if (preg_match('/\b100\s*\/\s*110\b/i', $label)) {
            return true;
        }

        return (bool) preg_match('/UGR86\s*\/\s*UGR89/i', $label);
    }

    private static function withReplacementCableDesignation(int $modelId, string $label): string
    {
        if ($label === '' || $label === 'Exact variant unavailable') {
            return $label;
        }

        if (! isset(self::REPLACEMENT_CABLE_MODEL_IDS[$modelId])) {
            return $label;
        }

        if (preg_match('/\bCable\b/i', $label)) {
            return $label;
        }

        return rtrim($label).' Cable';
    }
}
