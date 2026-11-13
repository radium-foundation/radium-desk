<?php

namespace App\Services\Oem\Morpho\Auth;

use App\Services\Oem\Morpho\Contracts\MorphoAuthenticationProvider;

/**
 * Test-only authentication stub. Not valid for production OEM calls.
 */
final class MockMorphoAuthenticationProvider implements MorphoAuthenticationProvider
{
    public function headers(): array
    {
        return [
            'Authorization' => 'MOCK-TEST-NOT-FOR-PRODUCTION',
            'SecurityStamp' => '00000000-0000-0000-0000-000000000000',
        ];
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
