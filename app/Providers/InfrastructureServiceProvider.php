<?php

namespace App\Providers;

use App\Infrastructure\IntegrationHealth\IntegrationHealthService;
use App\Infrastructure\IntegrationHealth\IntegrationHealthRegistry;
use App\Infrastructure\IntegrationHealth\Probes\CashfreeIntegrationHealthProbe;
use App\Infrastructure\IntegrationHealth\Probes\PlaceholderIntegrationHealthProbe;
use App\Infrastructure\IntegrationHealth\Probes\RadiumBoxIntegrationHealthProbe;
use Illuminate\Support\ServiceProvider;

class InfrastructureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IntegrationHealthRegistry::class, function (): IntegrationHealthRegistry {
            $registry = new IntegrationHealthRegistry;

            $registry->register(new CashfreeIntegrationHealthProbe);
            $registry->register(new RadiumBoxIntegrationHealthProbe);
            $registry->register(new PlaceholderIntegrationHealthProbe('whatsapp', 'WhatsApp'));
            $registry->register(new PlaceholderIntegrationHealthProbe('email', 'Email'));
            $registry->register(new PlaceholderIntegrationHealthProbe('shipping', 'Shipping'));
            $registry->register(new PlaceholderIntegrationHealthProbe('ai', 'AI'));

            return $registry;
        });

        $this->app->singleton(IntegrationHealthService::class);
    }
}
