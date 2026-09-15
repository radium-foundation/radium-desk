<?php

namespace App\Support\HardwareFulfilment;

use App\Models\CommerceOrderItem;

/**
 * Deterministic hardware variant labels from stored commerce line FKs.
 * Does not query Box, inventory, or channel_sku_maps (no N+1).
 *
 * Mantra MFS option IDs verified 2026-09-09 against radiumbox_prod.products.
 * UGR model IDs verified via Owner P0-M1 channel_sku_maps (926→UGR86, 1723→UGR89).
 * Desk inventory SKUs RBMFS100L0 / RBMFS110L1 map model 945→L0 and 946→L1.
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
        1131 => 1,
        1132 => 2,
        1133 => 2,
    ];

    /**
     * @var array<int, string>
     */
    private const MANTRA_MFS_OTG = [
        995 => 'U',
        996 => 'UC',
        1126 => 'U',
        1127 => 'UC',
        1134 => 'U',
        1136 => 'U',
        1724 => 'C',
    ];

    /**
     * Verified Box model_id → operational identity (P0-M1 channel_sku_maps).
     *
     * @var array<int, array{primary: string, chipset?: string}>
     */
    private const CATALOG_MODELS = [
        406 => ['primary' => 'ProxKey'],
        926 => ['primary' => 'Radium Box UGR 86'],
        930 => ['primary' => 'Futronic FS80H'],
        931 => ['primary' => 'Futronic FS88H'],
        951 => ['primary' => 'MSO 1300 E3 L1'],
        970 => ['primary' => 'FM220 UFP'],
        1006 => ['primary' => 'Mantra Iris MIS 100 V2'],
        1693 => ['primary' => 'Mantra MARC11 L1'],
        1723 => ['primary' => 'Radium Box UGR 89', 'chipset' => 'NaviC'],
    ];

    /**
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
        $presentation = self::mantraMfsPresentation($item)
            ?? self::ugrGpsPresentation($item)
            ?? self::catalogModelPresentation($item);

        if ($presentation !== null) {
            return $presentation;
        }

        return self::unavailablePresentation($item);
    }

    public static function label(CommerceOrderItem $item): string
    {
        $canonical = self::forItem($item);
        if ($canonical !== null) {
            return $canonical;
        }

        $line = self::workspaceLine($item);
        if (! $line['ambiguous']) {
            return $line['label'];
        }

        return 'Exact variant unavailable';
    }

    public static function invoiceDescription(CommerceOrderItem $item, bool $annotateBundledRd = false): string
    {
        $canonical = self::forItem($item);
        $description = $canonical;
        if ($description === null) {
            $line = self::workspaceLine($item);
            $description = ! $line['ambiguous'] ? $line['primary'] : self::fallbackLabel($item);
        }

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
     * @return ?array{label: string, primary: string, secondary: ?string, title: string, ambiguous: bool}
     */
    private static function mantraMfsPresentation(CommerceOrderItem $item): ?array
    {
        $parts = self::mantraMfsParts($item);
        if ($parts === null) {
            return null;
        }

        $qty = self::itemQty($item);
        $primary = self::workspaceIdentity($parts);
        $secondary = self::mfsConfigTokens($parts, $qty);

        return self::buildPresentation($primary, $secondary, self::mfsTitle($parts, $qty), $qty);
    }

    /**
     * @return ?array{label: string, primary: string, secondary: ?string, title: string, ambiguous: bool}
     */
    private static function ugrGpsPresentation(CommerceOrderItem $item): ?array
    {
        $modelId = self::modelId($item);
        $catalog = self::CATALOG_MODELS[$modelId] ?? null;
        if ($catalog === null || ! str_starts_with($catalog['primary'], 'Radium Box UGR')) {
            return null;
        }

        $qty = self::itemQty($item);
        $primary = $catalog['primary'];
        $tokens = [];
        if (($catalog['chipset'] ?? null) !== null) {
            $tokens[] = $catalog['chipset'];
        }
        $warranty = self::warrantyYears($item);
        if ($warranty !== null) {
            $tokens[] = 'W'.$warranty;
        }
        if ($qty !== null) {
            $tokens[] = 'Q'.$qty;
        }
        $secondary = $tokens === [] ? null : implode(' ', $tokens);

        $titleLines = [
            $primary,
            '',
            'Model            '.$primary,
        ];
        if (($catalog['chipset'] ?? null) !== null) {
            $titleLines[] = 'Chipset          '.$catalog['chipset'];
        }
        if ($warranty !== null) {
            $titleLines[] = 'Warranty         '.$warranty.' Year'.($warranty === 1 ? '' : 's').' (W'.$warranty.')';
        }
        if ($qty !== null) {
            $titleLines[] = 'Quantity         '.$qty.' (Q'.$qty.')';
        }

        return self::buildPresentation($primary, $secondary, implode("\n", $titleLines), $qty);
    }

    /**
     * @return ?array{label: string, primary: string, secondary: ?string, title: string, ambiguous: bool}
     */
    private static function catalogModelPresentation(CommerceOrderItem $item): ?array
    {
        $modelId = self::modelId($item);
        $catalog = self::CATALOG_MODELS[$modelId] ?? null;
        if ($catalog === null) {
            return null;
        }

        $qty = self::itemQty($item);
        $primary = $catalog['primary'];
        $tokens = self::genericConfigTokens($item, $qty);
        $secondary = $tokens === [] ? null : implode(' ', $tokens);

        $titleLines = [$primary, '', 'Model            '.$primary];
        $rd = self::rdYears($item);
        if ($rd !== null) {
            $titleLines[] = 'RD Service       '.$rd.' Year'.($rd === 1 ? '' : 's').' (R'.$rd.')';
        }
        $warranty = self::warrantyYears($item);
        if ($warranty !== null) {
            $titleLines[] = 'Warranty         '.$warranty.' Year'.($warranty === 1 ? '' : 's').' (W'.$warranty.')';
        }
        $otg = self::otgCode($item);
        if ($otg !== null) {
            $titleLines[] = 'USB / OTG        '.self::otgLabel($otg).' ('.$otg.')';
        }
        if ($qty !== null) {
            $titleLines[] = 'Quantity         '.$qty.' (Q'.$qty.')';
        }

        return self::buildPresentation($primary, $secondary, implode("\n", $titleLines), $qty);
    }

    /**
     * @return array{label: string, primary: string, secondary: ?string, title: string, ambiguous: bool}
     */
    private static function unavailablePresentation(CommerceOrderItem $item): array
    {
        $fallback = self::fallbackLabel($item);
        $ambiguous = self::isAmbiguousMarketingDescription($fallback);

        return [
            'label' => $ambiguous ? 'Exact variant unavailable' : $fallback,
            'primary' => $ambiguous ? 'Exact variant unavailable' : $fallback,
            'secondary' => $ambiguous ? 'Order metadata incomplete — verify before allocating serial' : null,
            'title' => $ambiguous
                ? 'Product variant could not be resolved from order line FKs. Verify commerce line model/configuration before allocating.'
                : $fallback,
            'ambiguous' => $ambiguous,
        ];
    }

    /**
     * @return array{label: string, primary: string, secondary: ?string, title: string, ambiguous: bool}
     */
    private static function buildPresentation(string $primary, ?string $secondary, string $title, ?int $qty): array
    {
        $label = trim($primary.($secondary !== null && $secondary !== '' ? ' '.$secondary : ''));

        return [
            'label' => $label,
            'primary' => $primary,
            'secondary' => $secondary,
            'title' => $title,
            'ambiguous' => false,
        ];
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
        $modelId = self::modelId($item);
        $model = self::MANTRA_MFS_MODELS[$modelId] ?? null;
        if ($model === null) {
            return null;
        }

        $rd = self::rdYears($item);
        $warranty = self::warrantyYears($item);
        $otg = self::otgCode($item);

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
    private static function workspaceIdentity(array $parts): string
    {
        return trim(self::FAMILY_MANTRA_MFS.' '.$parts['model'].' '.$parts['rd_level']);
    }

    /**
     * @param  array{model: string, rd_level: string, rd_years: int, warranty_years: int, otg_code: string}  $parts
     */
    private static function mfsConfigTokens(array $parts, ?int $qty): string
    {
        $tokens = [
            'R'.$parts['rd_years'],
            'W'.$parts['warranty_years'],
            $parts['otg_code'],
        ];

        if ($qty !== null) {
            $tokens[] = 'Q'.$qty;
        }

        return implode(' ', $tokens);
    }

    /**
     * @return list<string>
     */
    private static function genericConfigTokens(CommerceOrderItem $item, ?int $qty): array
    {
        $tokens = [];
        $rd = self::rdYears($item);
        if ($rd !== null) {
            $tokens[] = 'R'.$rd;
        }
        $warranty = self::warrantyYears($item);
        if ($warranty !== null) {
            $tokens[] = 'W'.$warranty;
        }
        $otg = self::otgCode($item);
        if ($otg !== null) {
            $tokens[] = $otg;
        }
        if ($qty !== null) {
            $tokens[] = 'Q'.$qty;
        }

        return $tokens;
    }

    /**
     * @param  array{model: string, rd_level: string, rd_years: int, warranty_years: int, otg_code: string}  $parts
     */
    private static function mfsTitle(array $parts, ?int $qty): string
    {
        $yearLabel = static fn (int $years): string => $years.' Year'.($years === 1 ? '' : 's');

        $lines = [
            self::workspaceIdentity($parts),
            '',
            'Model            '.self::FAMILY_MANTRA_MFS.' '.$parts['model'],
            'RD Level         '.$parts['rd_level'],
            'RD Service       '.$yearLabel($parts['rd_years']).' (R'.$parts['rd_years'].')',
            'Warranty         '.$yearLabel($parts['warranty_years']).' (W'.$parts['warranty_years'].')',
            'USB / OTG        '.self::otgLabel($parts['otg_code']).' ('.$parts['otg_code'].')',
        ];

        if ($qty !== null) {
            $lines[] = 'Quantity         '.$qty.' (Q'.$qty.')';
        }

        return implode("\n", $lines);
    }

    private static function modelId(CommerceOrderItem $item): int
    {
        return $item->model_id !== null ? (int) $item->model_id : 0;
    }

    private static function itemQty(CommerceOrderItem $item): ?int
    {
        return $item->qty !== null ? (int) $item->qty : null;
    }

    private static function rdYears(CommerceOrderItem $item): ?int
    {
        $rdId = $item->rdserviceid !== null ? (int) $item->rdserviceid : 0;

        return self::MANTRA_MFS_RD_YEARS[$rdId] ?? null;
    }

    private static function warrantyYears(CommerceOrderItem $item): ?int
    {
        $amcId = $item->amcid !== null ? (int) $item->amcid : 0;

        return self::MANTRA_MFS_WARRANTY_YEARS[$amcId] ?? null;
    }

    private static function otgCode(CommerceOrderItem $item): ?string
    {
        $otgId = $item->otgid !== null ? (int) $item->otgid : 0;

        return self::MANTRA_MFS_OTG[$otgId] ?? null;
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
        if (preg_match('/\b100\s*\/\s*110\b/i', $label)) {
            return true;
        }

        if (preg_match('/UGR\s*86\s*\/\s*UGR\s*89/i', $label)) {
            return true;
        }

        return (bool) preg_match('/UGR86\s*\/\s*UGR89/i', $label);
    }
}
