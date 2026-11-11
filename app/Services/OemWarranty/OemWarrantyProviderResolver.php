<?php

namespace App\Services\OemWarranty;

use App\Enums\OemWarrantyProvider;
use App\Models\Order;
use Illuminate\Support\Str;

final class OemWarrantyProviderResolver
{
    /**
     * @return array{suggested: ?OemWarrantyProvider, requires_selection: bool, reason: string}
     */
    public function resolve(Order $order): array
    {
        $candidates = [];

        $brand = Str::lower((string) ($order->deviceModel?->brand ?? ''));
        $modelName = Str::lower((string) ($order->displayDeviceModelName() ?? $order->device_model ?? ''));
        $productName = Str::lower((string) ($order->product_name ?? ''));

        $haystack = trim("{$brand} {$modelName} {$productName}");

        if ($this->mentionsMantra($haystack)) {
            $candidates[OemWarrantyProvider::Mantratec->value] = true;
        }

        if ($this->mentionsMorpho($haystack)) {
            $candidates[OemWarrantyProvider::Morpho->value] = true;
        }

        $values = array_keys($candidates);

        if (count($values) === 1) {
            return [
                'suggested' => OemWarrantyProvider::from($values[0]),
                'requires_selection' => false,
                'reason' => 'device_metadata',
            ];
        }

        if (count($values) > 1) {
            return [
                'suggested' => null,
                'requires_selection' => true,
                'reason' => 'ambiguous_device_metadata',
            ];
        }

        return [
            'suggested' => null,
            'requires_selection' => true,
            'reason' => 'unknown_device_metadata',
        ];
    }

    private function mentionsMantra(string $haystack): bool
    {
        return Str::contains($haystack, ['mantra', 'mfs ', 'mfs1', 'mis ', 'mistab', 'mfstab']);
    }

    private function mentionsMorpho(string $haystack): bool
    {
        return Str::contains($haystack, ['morpho', 'idemia', 'mso', 'ms0', 'cogent', 'sagem']);
    }
}
