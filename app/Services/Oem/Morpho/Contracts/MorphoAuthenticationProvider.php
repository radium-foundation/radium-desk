<?php

namespace App\Services\Oem\Morpho\Contracts;

interface MorphoAuthenticationProvider
{
    /**
     * @return array<string, string>
     */
    public function headers(): array;

    public function isAvailable(): bool;
}
