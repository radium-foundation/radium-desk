<?php

namespace App\Providers;

use App\Services\Oem\Morpho\Auth\ConfiguredMorphoAuthenticationProvider;
use App\Services\Oem\Morpho\Auth\MockMorphoAuthenticationProvider;
use App\Services\Oem\Morpho\Contracts\MorphoAuthenticationProvider;
use App\Services\Oem\Morpho\Contracts\MorphoSerialLookupPort;
use App\Services\Oem\Morpho\Contracts\MorphoTransport;
use App\Services\Oem\Morpho\MorphoAdapterConfiguration;
use App\Services\Oem\Morpho\MorphoOtbsAdapterService;
use App\Services\Oem\Morpho\MorphoResponseParser;
use App\Services\Oem\Morpho\MorphoSerialInputValidator;
use App\Services\Oem\Morpho\Transport\MockMorphoTransport;
use App\Services\Oem\Morpho\Transport\UnavailableLiveMorphoTransport;
use Illuminate\Support\ServiceProvider;

class MorphoOtbsAdapterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            base_path('config/morpho_otbs_adapter.php'),
            'morpho_otbs_adapter',
        );

        $this->app->singleton(MorphoAdapterConfiguration::class, fn (): MorphoAdapterConfiguration => MorphoAdapterConfiguration::fromConfig());

        $this->app->singleton(MorphoAuthenticationProvider::class, function ($app): MorphoAuthenticationProvider {
            $configuration = $app->make(MorphoAdapterConfiguration::class);

            if ($configuration->usesMockDriver()) {
                return new MockMorphoAuthenticationProvider;
            }

            return new ConfiguredMorphoAuthenticationProvider($configuration);
        });

        $this->app->singleton(MorphoTransport::class, function ($app): MorphoTransport {
            $configuration = $app->make(MorphoAdapterConfiguration::class);

            if ($configuration->usesMockDriver()) {
                return new MockMorphoTransport;
            }

            return new UnavailableLiveMorphoTransport;
        });

        $this->app->singleton(MorphoSerialLookupPort::class, MorphoOtbsAdapterService::class);

        $this->app->singleton(MorphoOtbsAdapterService::class, function ($app): MorphoOtbsAdapterService {
            return new MorphoOtbsAdapterService(
                transport: $app->make(MorphoTransport::class),
                authenticationProvider: $app->make(MorphoAuthenticationProvider::class),
                serialInputValidator: new MorphoSerialInputValidator,
                responseParser: new MorphoResponseParser,
                configuration: $app->make(MorphoAdapterConfiguration::class),
            );
        });
    }
}
