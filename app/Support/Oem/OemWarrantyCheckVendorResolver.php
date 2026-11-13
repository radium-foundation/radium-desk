<?php

namespace App\Support\Oem;

use App\Models\Order;

/**
 * Maps resolved Desk device models to OEM warranty-check vendors.
 * Does not alter device model resolution on orders.
 */
final class OemWarrantyCheckVendorResolver
{
    public function resolveForOrder(Order $order): ?OemWarrantyCheckVendor
    {
        $model = $this->normalizeModelName($order->displayDeviceModelName());

        if ($model === null) {
            return null;
        }

        return match ($model) {
            'mis 100', 'mfs 110' => OemWarrantyCheckVendor::Mantra,
            'mso e3', 'marc 11' => OemWarrantyCheckVendor::Morpho,
            'fm 220', 'pb 1000' => null,
            default => null,
        };
    }

    private function normalizeModelName(?string $modelName): ?string
    {
        if (! filled($modelName)) {
            return null;
        }

        $collapsed = preg_replace('/\s+/', ' ', strtolower(trim($modelName)));

        return is_string($collapsed) && $collapsed !== '' ? $collapsed : null;
    }
}
