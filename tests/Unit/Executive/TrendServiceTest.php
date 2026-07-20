<?php

namespace Tests\Unit\Executive;

use App\Data\Executive\ExecutiveMetricDto;
use App\Data\Executive\ExecutiveMetricPeriod;
use App\Enums\ExecutiveSnapshotGranularity;
use App\Enums\ExecutiveTrendDirection;
use App\Enums\PlatformHealthStatus;
use App\Models\ExecutiveMetricSnapshot;
use App\Services\Executive\Trends\TrendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TrendServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-20 11:40:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function liveMetric(string $id, int $value, string $title = 'Metric'): ExecutiveMetricDto
    {
        return new ExecutiveMetricDto(
            id: $id,
            title: $title,
            value: $value,
            formattedValue: (string) $value,
            status: PlatformHealthStatus::Healthy,
            subtitle: null,
            lastUpdated: now(),
            detailUrl: null,
            icon: 'bi-circle',
            period: ExecutiveMetricPeriod::Today,
            currentValue: $value,
        );
    }

    private function seedHourly(string $key, Carbon $time, float $value): void
    {
        ExecutiveMetricSnapshot::query()->create([
            'metric_key' => $key,
            'snapshot_time' => $time->copy()->startOfHour(),
            'metric_value' => $value,
            'status' => 'healthy',
            'granularity' => ExecutiveSnapshotGranularity::Hourly,
            'metadata' => [],
            'created_at' => now(),
        ]);
    }

    public function test_lower_better_increase_is_negative_direction(): void
    {
        $this->seedHourly('critical_cases', now()->subDay()->startOfHour(), 10);

        $enriched = app(TrendService::class)->enrich([
            $this->liveMetric('critical_cases', 12, 'Critical Cases'),
        ])[0];

        $this->assertSame(10.0, $enriched->previousValue);
        $this->assertSame(20.0, $enriched->trendPercentage);
        $this->assertSame(ExecutiveTrendDirection::Negative, $enriched->trendDirection);
        $this->assertSame('Compared to yesterday', $enriched->comparisonLabel);
        $this->assertSame('▲ 20%', $enriched->trend['label'] ?? null);
    }

    public function test_lower_better_decrease_is_positive_direction(): void
    {
        $this->seedHourly('refund_queue', now()->subDay()->startOfHour(), 8);

        $enriched = app(TrendService::class)->enrich([
            $this->liveMetric('refund_queue', 6, 'Refund Queue'),
        ])[0];

        $this->assertSame(-25.0, $enriched->trendPercentage);
        $this->assertSame(ExecutiveTrendDirection::Positive, $enriched->trendDirection);
        $this->assertSame('▼ 25%', $enriched->trend['label'] ?? null);
    }

    public function test_higher_better_increase_is_positive_direction(): void
    {
        $this->seedHourly('orders_today', now()->subDay()->startOfHour(), 10);

        $enriched = app(TrendService::class)->enrich([
            $this->liveMetric('orders_today', 15, 'Orders Today'),
        ])[0];

        $this->assertSame(50.0, $enriched->trendPercentage);
        $this->assertSame(ExecutiveTrendDirection::Positive, $enriched->trendDirection);
    }

    public function test_missing_history_is_unknown(): void
    {
        $enriched = app(TrendService::class)->enrich([
            $this->liveMetric('open_cases', 3),
        ])[0];

        $this->assertNull($enriched->previousValue);
        $this->assertNull($enriched->trendPercentage);
        $this->assertSame(ExecutiveTrendDirection::Unknown, $enriched->trendDirection);
    }

    public function test_seven_day_average_uses_daily_representatives(): void
    {
        for ($offset = 1; $offset <= 7; $offset++) {
            $day = now()->subDays($offset);
            $this->seedHourly('orders_today', $day->copy()->setTime(9, 0), 10);
            $this->seedHourly('orders_today', $day->copy()->setTime(18, 0), $offset * 2);
        }

        $enriched = app(TrendService::class)->enrich([
            $this->liveMetric('orders_today', 20, 'Orders Today'),
        ])[0];

        // Last hourly each day is 2,4,6,8,10,12,14 → avg 8
        $this->assertSame(8.0, $enriched->sevenDayAverage);
    }
}
