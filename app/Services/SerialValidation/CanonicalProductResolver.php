<?php

namespace App\Services\SerialValidation;

use App\Support\DeviceModelFormatter;

class CanonicalProductResolver
{
    /**
     * @param  list<string>|null  $supportedProducts
     */
    public function __construct(
        private readonly ?array $supportedProducts = null,
    ) {}

    public function resolve(?string $product): ?string
    {
        if (! filled($product)) {
            return null;
        }

        $supportedProducts = $this->supportedProducts ?? config('serial_validation.supported_products', []);
        $shortDisplay = DeviceModelFormatter::shortDisplay($product);

        if ($shortDisplay !== null && in_array($shortDisplay, $supportedProducts, true)) {
            return $shortDisplay;
        }

        $normalized = strtoupper(trim($product));

        if (in_array($normalized, $supportedProducts, true)) {
            return $normalized;
        }

        foreach ($supportedProducts as $supportedProduct) {
            if (str_starts_with($normalized, str_replace(' ', '', $supportedProduct))) {
                return $supportedProduct;
            }
        }

        return $shortDisplay ?? trim($product);
    }
}
