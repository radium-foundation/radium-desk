<?php

namespace App\Providers;

use App\Services\Executive\ExecutiveMetricsCache;
use App\Services\Executive\ExecutiveMetricsContextBuilder;
use App\Services\Executive\ExecutiveMetricsService;
use App\Services\Executive\Insights\ExecutiveInsightEngine;
use App\Services\Executive\Providers\ActiveAgentsMetric;
use App\Services\Executive\Providers\AppointmentsTodayMetric;
use App\Services\Executive\Providers\CriticalCasesMetric;
use App\Services\Executive\Providers\CustomersWaitingMetric;
use App\Services\Executive\Providers\OpenCasesMetric;
use App\Services\Executive\Providers\OrdersTodayMetric;
use App\Services\Executive\Providers\RefundQueueMetric;
use App\Services\Executive\Providers\ResolvedTodayMetric;
use App\Services\Executive\Snapshots\ExecutiveSnapshotRepository;
use App\Services\Executive\Snapshots\ExecutiveSnapshotService;
use App\Services\Executive\Snapshots\ExecutiveSnapshotWriter;
use App\Services\Executive\Trends\TrendService;
use Illuminate\Support\ServiceProvider;

class ExecutiveMetricsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ExecutiveMetricsCache::class);
        $this->app->singleton(ExecutiveMetricsContextBuilder::class);
        $this->app->singleton(ExecutiveSnapshotRepository::class);
        $this->app->singleton(ExecutiveSnapshotWriter::class);
        $this->app->singleton(TrendService::class);
        $this->app->singleton(ExecutiveInsightEngine::class);
        $this->app->singleton(ExecutiveSnapshotService::class);

        $this->app->singleton(ExecutiveMetricsService::class, function ($app): ExecutiveMetricsService {
            $providerClasses = [
                OpenCasesMetric::class,
                CriticalCasesMetric::class,
                ActiveAgentsMetric::class,
                CustomersWaitingMetric::class,
                RefundQueueMetric::class,
                OrdersTodayMetric::class,
                ResolvedTodayMetric::class,
                AppointmentsTodayMetric::class,
            ];

            return new ExecutiveMetricsService(
                contextBuilder: $app->make(ExecutiveMetricsContextBuilder::class),
                cache: $app->make(ExecutiveMetricsCache::class),
                trendService: $app->make(TrendService::class),
                providers: array_map(
                    fn (string $class) => $app->make($class),
                    $providerClasses,
                ),
            );
        });
    }
}
