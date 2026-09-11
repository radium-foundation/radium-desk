<?php

namespace App\Support\HardwareFulfilment;

use App\Models\CommerceOrderItem;

/**
 * Deterministic hardware variant labels from stored commerce line FKs.
 * Does not query Box, inventory, or channel_sku_maps (no N+1).
 *
 * Mantra MFS option IDs were verified 2026-09-09 against radiumbox_prod.products:
 * models 945/946 (attribute 5), RD attribute 1, warranty attribute 2, OTG attribute 4.
 * OTG 996/1127 = USB + Type-C (verified P-03-09-04 / radiumbox_prod attribute 4).
 * Desk inventory SKUs RBMFS100L0 / RBMFS110L1 map model 945 → L0 and 946 → L1.
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
     * @var array<int, string>
     */
    private const MANTRA_MFS_RD_LEVELS = [
        945 => 'L0',
        946 => 'L1',
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
     * Compact OTG codes for canonical string and invoice use.
     *
     * @var array<int, string>
     */
    private const MANTRA_MFS_OTG = [
        995 => 'U',
        996 => 'UC',
        1126 => 'U',
        1127 => 'UC',
        1724 => 'C',
    ];

    /**
     * Human-readable OTG labels for workspace tooltips.
     *
     * @var array<string, string>
     */
    private const OTG_LABELS = [
        'U' => 'USB',
        'C' => 'USB-C',
        'UC' => 'USB + Type-C',
    ];

    public static function format(string $family, string $model, int $rdYears, int $warrantyYears, string $otgCode): string
    {
        return $family.' '.$model.' '.$rdYears.'R '.$warrantyYears.'W '.$otgCode;
    }

    public static function forItem(CommerceOrderItem $item): ?string
    {
        $parts = self::mantraMfsParts($item);
        if ($parts === null) {
            return null;
        }

        return self::format(
            self::FAMILY_MANTRA_MFS,
            $parts['model'],
            $parts['rd_years'],
            $parts['warranty_years'],
            $parts['otg_code'],
        );
    }

    /**
     * @return array{
     *     label: string,
     *     primary: string,
     *     secondary: ?string,
     *     title: string,
     *     ambiguous: bool
     * }
     */
    public static function workspaceLine(CommerceOrderItem $item): array
    {
        $parts = self::mantraMfsParts($item);
        if ($parts !== null) {
            $canonical = self::format(
                self::FAMILY_MANTRA_MFS,
                $parts['model'],
                $parts['rd_years'],
                $parts['warranty_years'],
                $parts['otg_code'],
            );
            $qty = $item->qty !== null ? (int) $item->qty : null;
            $label = $qty !== null ? $canonical.' · '.$qty.' Q' : $canonical;

            return [
                'label' => $label,
                'primary' => trim(self::FAMILY_MANTRA_MFS.' '.$parts['model'].' · '.$parts['rd_level']),
                'secondary' => self::workspaceSecondary($parts, $qty),
                'title' => self::workspaceTitle($parts, $qty),
                'ambiguous' => false,
            ];
        }

        $fallback = self::fallbackLabel($item);
        $ambiguous = self::isAmbiguousMarketingDescription($fallback);

        return [
            'label' => $ambiguous ? 'Exact variant unavailable' : $fallback,
            'primary' => $ambiguous ? 'Exact variant unavailable' : $fallback,
            'secondary' => $ambiguous ? 'Order metadata incomplete — verify before allocating serial' : null,
            'title' => $ambiguous
                ? 'Product variant could not be resolved from order line FKs. Verify commerce line model/RD/warranty/OTG before allocating.'
                : $fallback,
            'ambiguous' => $ambiguous,
        ];
    }

    public static function label(CommerceOrderItem $item): string
    {
        $canonical = self::forItem($item);
        if ($canonical !== null) {
            return $canonical;
        }

        $fallback = self::fallbackLabel($item);
        if (self::isAmbiguousMarketingDescription($fallback)) {
            return 'Exact variant unavailable';
        }

        return $fallback;
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

    public static function otgLabel(string $otgCode): string
    {
        return self::OTG_LABELS[$otgCode] ?? $otgCode;
    }

    /**
     * @return ?array{
     *     model: string,
     *     rd_level: string,
     *     rd_years: int,
     *     warranty_years: int,
     *     otg_code: string
     * }
     */
    private static function mantraMfsParts(CommerceOrderItem $item): ?array
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

        return [
            'model' => $model,
            'rd_level' => self::MANTRA_MFS_RD_LEVELS[$modelId] ?? '—',
            'rd_years' => $rd,
            'warranty_years' => $warranty,
            'otg_code' => $otg,
        ];
    }

    /**
     * @param  array{model: string, rd_level: string, rd_years: int, warranty_years: int, otg_code: string}  $parts
     */
    private static function workspaceSecondary(array $parts, ?int $qty): string
    {
        $segments = [
            'RD '.$parts['rd_years'].'Y',
            'Warranty '.$parts['warranty_years'].'Y',
            self::otgLabel($parts['otg_code']),
        ];

        if ($qty !== null) {
            $segments[] = 'Qty '.$qty;
        }

        return implode(' · ', $segments);
    }

    /**
     * @param  array{model: string, rd_level: string, rd_years: int, warranty_years: int, otg_code: string}  $parts
     */
    private static function workspaceTitle(array $parts, ?int $qty): string
    {
        $lines = [
            'Product: '.self::FAMILY_MANTRA_MFS.' '.$parts['model'],
            'RD Level: '.$parts['rd_level'],
            'RD Service: '.$parts['rd_years'].' Year'.($parts['rd_years'] === 1 ? '' : 's'),
            'Warranty: '.$parts['warranty_years'].' Year'.($parts['warranty_years'] === 1 ? '' : 's'),
            'OTG / USB: '.self::otgLabel($parts['otg_code']),
            $parts['rd_years'].'R = '.$parts['rd_years'].' Year RD Service',
            $parts['warranty_years'].'W = '.$parts['warranty_years'].' Year Warranty',
        ];

        if ($qty !== null) {
            $lines[] = 'Quantity: '.$qty;
        }

        return implode(' · ', $lines);
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
        return (bool) preg_match('/\b100\s*\/\s*110\b/i', $label);
    }
}
