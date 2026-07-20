<?php

namespace Tests\Unit\Executive;

use App\Data\Executive\ExecutiveMetricPeriod;
use App\Data\Executive\ExecutiveMetricsContext;
use App\Enums\PlatformHealthStatus;
use App\Services\Executive\Providers\CriticalCasesMetric;
use App\Services\Executive\Providers\CustomersWaitingMetric;
use App\Services\Executive\Providers\RefundQueueMetric;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExecutiveMetricStatusRulesTest extends TestCase
{
    private function context(array $overrides = []): ExecutiveMetricsContext
    {
        return new ExecutiveMetricsContext(
            period: ExecutiveMetricPeriod::Today,
            dayStart: Carbon::parse('2026-07-20 00:00:00'),
            dayEnd: Carbon::parse('2026-07-20 23:59:59'),
            openCases: $overrides['openCases'] ?? 0,
            criticalCases: $overrides['criticalCases'] ?? 0,
            activeAgents: $overrides['activeAgents'] ?? 0,
            customersWaiting: $overrides['customersWaiting'] ?? 0,
            refundQueue: $overrides['refundQueue'] ?? 0,
            ordersToday: $overrides['ordersToday'] ?? 0,
            resolvedToday: $overrides['resolvedToday'] ?? 0,
            appointmentsToday: $overrides['appointmentsToday'] ?? 0,
            computedAt: Carbon::parse('2026-07-20 11:40:00'),
        );
    }

    public function test_critical_cases_status_thresholds(): void
    {
        $metric = new CriticalCasesMetric;

        $this->assertSame(
            PlatformHealthStatus::Healthy,
            $metric->fromContext($this->context(['criticalCases' => 0]))->status,
        );
        $this->assertSame(
            PlatformHealthStatus::Warning,
            $metric->fromContext($this->context(['criticalCases' => 1]))->status,
        );
        $this->assertSame(
            PlatformHealthStatus::Warning,
            $metric->fromContext($this->context(['criticalCases' => 2]))->status,
        );
        $this->assertSame(
            PlatformHealthStatus::Critical,
            $metric->fromContext($this->context(['criticalCases' => 3]))->status,
        );
    }

    public function test_customers_waiting_status_thresholds(): void
    {
        $metric = new CustomersWaitingMetric;

        $this->assertSame(
            PlatformHealthStatus::Healthy,
            $metric->fromContext($this->context(['customersWaiting' => 3]))->status,
        );
        $this->assertSame(
            PlatformHealthStatus::Warning,
            $metric->fromContext($this->context(['customersWaiting' => 4]))->status,
        );
        $this->assertSame(
            PlatformHealthStatus::Critical,
            $metric->fromContext($this->context(['customersWaiting' => 10]))->status,
        );
    }

    public function test_refund_queue_status_thresholds(): void
    {
        $metric = new RefundQueueMetric;

        $this->assertSame(
            PlatformHealthStatus::Healthy,
            $metric->fromContext($this->context(['refundQueue' => 0]))->status,
        );
        $this->assertSame(
            PlatformHealthStatus::Warning,
            $metric->fromContext($this->context(['refundQueue' => 1]))->status,
        );
        $this->assertSame(
            PlatformHealthStatus::Critical,
            $metric->fromContext($this->context(['refundQueue' => 5]))->status,
        );
    }
}
