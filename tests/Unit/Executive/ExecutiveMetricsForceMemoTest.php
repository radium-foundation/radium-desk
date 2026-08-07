<?php

namespace Tests\Unit\Executive;

use App\Data\Executive\ExecutiveMetricPeriod;
use App\Services\Executive\ExecutiveMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExecutiveMetricsForceMemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_force_gets_share_one_context_build(): void
    {
        $service = app(ExecutiveMetricsService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $service->get('open_cases', ExecutiveMetricPeriod::Today, force: true);
        $service->get('critical_cases', ExecutiveMetricPeriod::Today, force: true);
        $service->get('customers_waiting', ExecutiveMetricPeriod::Today, force: true);
        $service->get('refund_queue', ExecutiveMetricPeriod::Today, force: true);
        $service->get('active_agents', ExecutiveMetricPeriod::Today, force: true);
        $service->get('orders_today', ExecutiveMetricPeriod::Today, force: true);
        $service->get('appointments_today', ExecutiveMetricPeriod::Today, force: true);
        $service->get('resolved_today', ExecutiveMetricPeriod::Today, force: true);

        $openCasesAggregates = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_contains(strtolower($q['query']), 'open_cases')
                && str_contains(strtolower($q['query']), 'critical_cases'))
            ->count();

        $this->assertSame(1, $openCasesAggregates);
    }
}
