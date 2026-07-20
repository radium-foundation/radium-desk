<?php

use App\Providers\AppServiceProvider;
use App\Providers\ExecutiveMetricsServiceProvider;
use App\Providers\InfrastructureServiceProvider;
use App\Providers\PlatformDashboardServiceProvider;

return [
    AppServiceProvider::class,
    InfrastructureServiceProvider::class,
    ExecutiveMetricsServiceProvider::class,
    PlatformDashboardServiceProvider::class,
];
