<?php

namespace App\Services\Oem\Morpho\Transport;

use App\Services\Oem\Morpho\Contracts\MorphoTransport;
use App\Services\Oem\Morpho\Data\MorphoRawResponse;
use App\Services\Oem\Morpho\Exceptions\MorphoLiveIntegrationDisabledException;

/**
 * Placeholder for future OEM-authorized HTTP transport. Always refuses outbound calls in P-13-93.
 */
final class UnavailableLiveMorphoTransport implements MorphoTransport
{
    public function get(string $path, array $headers = []): MorphoRawResponse
    {
        throw new MorphoLiveIntegrationDisabledException(
            'Live Morpho HTTP transport is not connected in this release.',
        );
    }

    public function post(string $path, array $payload, array $headers = []): MorphoRawResponse
    {
        throw new MorphoLiveIntegrationDisabledException(
            'Live Morpho HTTP transport is not connected in this release.',
        );
    }
}
