<?php

namespace App\Services\HardwareFulfilment\Data;

use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\Order;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;

/**
 * Presentation catalog from existing order/product relations.
 * Does not invent names or call providers.
 */
final class HardwareFulfilmentProductLines
{
    /**
     * @return array{
     *     lines: list<array{label: string, qty: ?int}>,
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
                $lines[] = ['label' => $name, 'qty' => null];
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
            'product' => $lines[0]['label'],
            'quantity' => $hasQty ? (string) $qtyTotal : '',
        ];
    }

    /**
     * @return list<array{label: string, qty: ?int}>
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

            $label = trim((string) $item->description);
            if ($label === '') {
                $label = trim((string) ($item->sku ?: $item->catalog_sku ?: ''));
            }
            if ($label === '') {
                continue;
            }

            $lines[] = [
                'label' => $label,
                'qty' => $item->qty !== null ? (int) $item->qty : null,
            ];
        }

        return $lines;
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
        $head = $first['qty'] !== null
            ? $first['label'].' · '.$first['qty'].' Q'
            : $first['label'];
        $extra = count($lines) - 1;

        return $extra > 0 ? $head.' +'.$extra : $head;
    }
}
