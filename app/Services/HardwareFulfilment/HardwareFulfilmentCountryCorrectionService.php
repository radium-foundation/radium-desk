<?php

namespace App\Services\HardwareFulfilment;

use App\Models\HardwareFulfilment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class HardwareFulfilmentCountryCorrectionService
{
    public const HARDWARE_SHIPMENT_COUNTRY = 'India';

    public function correct(HardwareFulfilment $fulfilment, string $country, User $actor): HardwareFulfilment
    {
        $country = trim($country);
        if ($country === '') {
            throw ValidationException::withMessages([
                'country' => 'Country must be supplied explicitly. It is not inferred.',
            ]);
        }

        if (strlen($country) > 64) {
            throw ValidationException::withMessages([
                'country' => 'Country must be 64 characters or fewer.',
            ]);
        }

        $fulfilment->loadMissing('commerceOrder');
        $order = $fulfilment->commerceOrder;
        if ($order === null) {
            throw ValidationException::withMessages([
                'country' => 'Hardware fulfilment is missing its commerce order.',
            ]);
        }

        $structured = is_array($order->shipping_address_structured) ? $order->shipping_address_structured : [];
        $existing = trim((string) ($structured['country'] ?? ''));
        if ($existing !== '') {
            throw ValidationException::withMessages([
                'country' => 'Shipping country is already present on the structured address and cannot be overwritten.',
            ]);
        }

        $overlay = trim((string) ($fulfilment->shipping_country_overlay ?? ''));
        if ($overlay !== '') {
            if ($overlay === $country) {
                return $fulfilment;
            }

            throw ValidationException::withMessages([
                'country' => 'A country overlay is already recorded and cannot be replaced.',
            ]);
        }

        $fulfilment->forceFill([
            'shipping_country_overlay' => $country,
            'shipping_country_overlay_at' => now(),
            'shipping_country_overlay_by_user_id' => $actor->id,
            'shipping_country_overlay_context' => [
                'commerce_order_id' => $order->id,
                'source_id' => $fulfilment->source_id,
                'structured_keys' => array_keys($structured),
                'previous_overlay' => null,
            ],
        ])->save();

        return $fulfilment->fresh() ?? $fulfilment;
    }

    public function canCorrect(HardwareFulfilment $fulfilment): bool
    {
        return false;
    }

    public function resolvedCountry(HardwareFulfilment $fulfilment): string
    {
        return self::HARDWARE_SHIPMENT_COUNTRY;
    }
}
