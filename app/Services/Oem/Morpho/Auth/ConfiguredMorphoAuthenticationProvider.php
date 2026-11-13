<?php

namespace App\Services\Oem\Morpho\Auth;

use App\Services\Oem\Morpho\Contracts\MorphoAuthenticationProvider;
use App\Services\Oem\Morpho\MorphoAdapterConfiguration;

final class ConfiguredMorphoAuthenticationProvider implements MorphoAuthenticationProvider
{
    public function __construct(
        private readonly MorphoAdapterConfiguration $configuration,
    ) {}

    public function headers(): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        return [
            'Authorization' => (string) config('morpho_otbs_adapter.live.authorization'),
            'SecurityStamp' => (string) config('morpho_otbs_adapter.live.security_stamp'),
        ];
    }

    public function isAvailable(): bool
    {
        if ($this->configuration->usesMockDriver()) {
            return false;
        }

        $authorization = trim((string) config('morpho_otbs_adapter.live.authorization'));
        $stamp = trim((string) config('morpho_otbs_adapter.live.security_stamp'));

        return $authorization !== '' && $stamp !== '' && (bool) config('morpho_otbs_adapter.live.enabled');
    }
}
