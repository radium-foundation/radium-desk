<?php

namespace Tests\Unit\Executive;

use App\Data\Executive\ExecutiveMetricDto;
use App\Data\Executive\ExecutiveMetricPeriod;
use App\Data\Executive\ExecutiveMetricsSnapshot;
use App\Enums\ExecutiveTrendDirection;
use App\Enums\PlatformHealthStatus;
use App\Services\Executive\Insights\ExecutiveInsightEngine;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExecutiveInsightEngineTest extends TestCase
{
    private function metric(
        string $id,
        string $title,
        int $value,
        ?float $previous,
        ?float $percent,
        ExecutiveTrendDirection $direction,
        ?float $sevenDayAverage = null,
    ): ExecutiveMetricDto {
        return new ExecutiveMetricDto(
            id: $id,
            title: $title,
            value: $value,
            formattedValue: (string) $value,
            status: PlatformHealthStatus::Healthy,
            subtitle: null,
            lastUpdated: Carbon::parse('2026-07-20 11:40:00'),
            detailUrl: null,
            icon: 'bi-circle',
            period: ExecutiveMetricPeriod::Today,
            currentValue: $value,
            previousValue: $previous,
            sevenDayAverage: $sevenDayAverage,
            trendPercentage: $percent,
            trendDirection: $direction,
            comparisonLabel: 'Compared to yesterday',
        );
    }

    public function test_builds_structured_insights_without_llm_text_generation(): void
    {
        $snapshot = new ExecutiveMetricsSnapshot(
            period: ExecutiveMetricPeriod::Today,
            metrics: [
                $this->metric('critical_cases', 'Critical Cases', 12, 10, 20.0, ExecutiveTrendDirection::Negative),
                $this->metric('refund_queue', 'Refund Queue', 6, 8, -25.0, ExecutiveTrendDirection::Positive),
                $this->metric('appointments_today', 'Appointments Today', 2, 5, -60.0, ExecutiveTrendDirection::Negative, 10.0),
            ],
            generatedAt: Carbon::parse('2026-07-20 11:40:00'),
        );

        $insights = app(ExecutiveInsightEngine::class)->analyze($snapshot);
        $messages = array_map(fn ($insight) => $insight->message, $insights);
        $codes = array_map(fn ($insight) => $insight->code, $insights);

        $this->assertContains('Critical Cases increased 20%.', $messages);
        $this->assertContains('Refund Queue improved 25%.', $messages);
        $this->assertContains('Appointments Today are below weekly average.', $messages);
        $this->assertContains('critical_cases_up', $codes);
        $this->assertContains('refund_queue_improved', $codes);
        $this->assertLessThanOrEqual(5, count($insights));
    }
}
