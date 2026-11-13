<?php

namespace Tests\Unit\Oem\Morpho;

use App\Services\Oem\Morpho\Exceptions\MorphoLiveIntegrationDisabledException;
use App\Services\Oem\Morpho\Transport\UnavailableLiveMorphoTransport;
use PHPUnit\Framework\TestCase;

class UnavailableLiveMorphoTransportTest extends TestCase
{
    public function test_live_transport_refuses_outbound_get(): void
    {
        $this->expectException(MorphoLiveIntegrationDisabledException::class);

        (new UnavailableLiveMorphoTransport)->get('/apiconnector/DeviceValidator/0000I000001');
    }
}
