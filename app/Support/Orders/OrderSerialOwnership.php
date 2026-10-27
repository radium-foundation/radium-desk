<?php

namespace App\Support\Orders;

use App\Models\Order;

final class OrderSerialOwnership
{
    public static function normalizeSerial(?string $serial): ?string
    {
        if ($serial === null) {
            return null;
        }

        $normalized = strtoupper(trim($serial));

        return $normalized === '' ? null : $normalized;
    }

    public static function findOwner(string $serial, ?int $excludeOrderDbId = null): ?Order
    {
        $normalized = self::normalizeSerial($serial);

        if ($normalized === null) {
            return null;
        }

        return Order::query()
            ->when($excludeOrderDbId !== null, fn ($query) => $query->whereKeyNot($excludeOrderDbId))
            ->where('serial_number', $normalized)
            ->first(['id', 'order_id', 'serial_number']);
    }

    public static function isAvailableForOrder(string $serial, ?int $forOrderDbId = null): bool
    {
        return self::findOwner($serial, $forOrderDbId) === null;
    }
}
