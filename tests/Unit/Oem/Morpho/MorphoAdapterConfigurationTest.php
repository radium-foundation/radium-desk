<?php

namespace Tests\Unit\Oem\Morpho;

use App\Services\Oem\Morpho\Exceptions\MorphoLiveIntegrationDisabledException;
use App\Services\Oem\Morpho\MorphoAdapterConfiguration;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MorphoAdapterConfigurationTest extends TestCase
{
    public function test_mock_driver_does_not_require_live_credentials(): void
    {
        Config::set('morpho_otbs_adapter.driver', 'mock');

        $configuration = MorphoAdapterConfiguration::fromConfig();

        $this->assertTrue($configuration->usesMockDriver());
    }

    public function test_live_driver_assertion_fails_without_credentials(): void
    {
        Config::set('morpho_otbs_adapter.driver', 'live');
        Config::set('morpho_otbs_adapter.live.enabled', false);
        Config::set('morpho_otbs_adapter.live.authorization', '');
        Config::set('morpho_otbs_adapter.live.security_stamp', '');

        $this->expectException(MorphoLiveIntegrationDisabledException::class);

        MorphoAdapterConfiguration::fromConfig()->assertLiveIntegrationAllowed();
    }
}
