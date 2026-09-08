<?php

namespace App\Services\HardwareFulfilment;

use App\Models\InventoryBranch;
use Illuminate\Validation\ValidationException;

/**
 * Pickup nickname comes from physical fulfilment branch only.
 * Customer / POS state is not an input.
 */
final class HardwarePickupResolver
{
    public function requireForBranch(?InventoryBranch $branch): string
    {
        if ($branch === null || ! $branch->is_active) {
            throw ValidationException::withMessages([
                'pickup' => 'Hardware shipment requires an active fulfilment branch. Customer state cannot substitute.',
            ]);
        }

        $key = match ($branch->code) {
            'DELHI-RETAIL' => 'delhi',
            'MUMBAI' => 'mumbai',
            default => null,
        };

        if ($key === null) {
            throw ValidationException::withMessages([
                'pickup' => sprintf('No Shiprocket pickup mapping exists for fulfilment branch %s.', $branch->code),
            ]);
        }

        $nickname = trim((string) config('shipping.pickup_locations.'.$key, ''));
        if ($nickname === '') {
            throw ValidationException::withMessages([
                'pickup' => sprintf(
                    'Shiprocket pickup nickname for %s is not configured.',
                    $key,
                ),
            ]);
        }

        return $nickname;
    }

    public function requirePostcodeForBranch(?InventoryBranch $branch): string
    {
        if ($branch === null || ! $branch->is_active) {
            throw ValidationException::withMessages([
                'pickup' => 'Hardware shipment requires an active fulfilment branch. Customer state cannot substitute.',
            ]);
        }

        $key = match ($branch->code) {
            'DELHI-RETAIL' => 'delhi',
            'MUMBAI' => 'mumbai',
            default => null,
        };

        if ($key === null) {
            throw ValidationException::withMessages([
                'pickup' => sprintf('No Shiprocket pickup mapping exists for fulfilment branch %s.', $branch->code),
            ]);
        }

        $postcode = preg_replace('/\D+/', '', (string) config('shipping.pickup_postcodes.'.$key, '')) ?? '';
        if (strlen($postcode) < 6) {
            throw ValidationException::withMessages([
                'pickup' => sprintf(
                    'Shiprocket pickup postcode for %s is not configured.',
                    $key,
                ),
            ]);
        }

        return $postcode;
    }
}
