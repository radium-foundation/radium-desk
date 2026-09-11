<?php

namespace App\Services\HardwareFulfilment\Data;

use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\Order;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Support\HardwareFulfilment\HardwareConfigurableVariantDisplay;

/**
 * Presentation catalog from existing order/product relations.
 * Does not invent names or call providers.
 */
final class HardwareFulfilmentProductLines
{
    /**
     * @return array{
     *     lines: list<array{
     *         label: string,
     *         qty: ?int,
     *         primary: string,
     *         secondary: ?string,
     *         title: string,
     *         ambiguous: bool
     *     }>,
     *     missing: bool,
     *     compact: string,
     *     product: string,
     *     quantity: string
     * }
     */
    public static function resolve(?CommerceOrder $commerce, ?Order $support = null): array
    {
        $lines = self::fromCommerce($commerce);
        if ($lines === [] && $support !== null) {
            $name = trim((string) ($support->product_name ?? ''));
            if ($name !== '') {
                $lines[] = self::supportLine($name);
            }
        }

        if ($lines === []) {
            return [
                'lines' => [],
                'missing' => true,
                'compact' => 'Product data missing',
                'product' => '',
                'quantity' => '',
            ];
        }

        $qtyTotal = 0;
        $hasQty = false;
        foreach ($lines as $line) {
            if ($line['qty'] !== null) {
                $qtyTotal += $line['qty'];
                $hasQty = true;
            }
        }

        return [
            'lines' => $lines,
            'missing' => false,
            'compact' => self::compact($lines),
            'product' => $lines[0]['primary'],
            'quantity' => $hasQty ? (string) $qtyTotal : '',
        ];
    }

    /**
     * @return list<array{
     *     label: string,
     *     qty: ?int,
     *     primary: string,
     *     secondary: ?string,
     *     title: string,
     *     ambiguous: bool
     * }>
     */
    private static function fromCommerce(?CommerceOrder $commerce): array
    {
        if ($commerce === null) {
            return [];
        }

        $lines = [];
        foreach ($commerce->items as $item) {
            if (! $item instanceof CommerceOrderItem) {
                continue;
            }
            if (! HardwareFulfilmentEligibility::isPhysicalCommerceItem($item)) {
                continue;
            }

            $presentation = HardwareConfigurableVariantDisplay::workspaceLine($item);
            if ($presentation['label'] === '') {
                continue;
            }

            $lines[] = [
                'label' => $presentation['label'],
                'qty' => $item->qty !== null ? (int) $item->qty : null,
                'primary' => $presentation['primary'],
                'secondary' => $presentation['secondary'],
                'title' => $presentation['title'],
                'ambiguous' => $presentation['ambiguous'],
            ];
        }

        return $lines;
    }

    /**
     * @return array{
     *     label: string,
     *     qty: ?int,
     *     primary: string,
     *     secondary: ?string,
     *     title: string,
     *     ambiguous: bool
     * }
     */
    private static function supportLine(string $name): array
    {
        $ambiguous = str_contains($name, '100 / 110');

        return [
            'label' => $name,
            'qty' => null,
            'primary' => $ambiguous ? 'Exact variant unavailable' : $name,
            'secondary' => $ambiguous ? 'Awaiting commerce handoff — verify before allocating serial' : null,
            'title' => $ambiguous
                ? 'Product variant details pending — commerce line FKs not available yet.'
                : $name,
            'ambiguous' => $ambiguous,
        ];
    }

    /**
     * @param  list<array{label: string, qty: ?int}>  $lines
     */
    public static function compact(array $lines): string
    {
        if ($lines === []) {
            return 'Product data missing';
        }

        $first = $lines[0];
        $head = $first['label'];
        if ($first['qty'] !== null && ! preg_match('/\bQ'.$first['qty'].'\b/', $head)) {
            $head .= ' · '.$first['qty'].' Q';
        }
        $extra = count($lines) - 1;

        return $extra > 0 ? $head.' +'.$extra : $head;
    }
}
