<?php

namespace Tests\Unit\Platform;

use App\Data\Platform\PlatformCardPayload;
use App\Enums\PlatformHealthStatus;
use App\Support\Platform\OperationsSnapshotScoring;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OperationsSnapshotThresholdTuningTest extends TestCase
{
    public function test_threshold_examples_from_requirements(): void
    {
        $cases = [
            [12.0, PlatformHealthStatus::Healthy],
            [18.0, PlatformHealthStatus::Warning],
            [29.0, PlatformHealthStatus::Warning],
            [30.0, PlatformHealthStatus::Critical],
            [48.0, PlatformHealthStatus::Critical],
        ];

        foreach ($cases as [$score, $expected]) {
            $this->assertSame(
                $expected,
                OperationsSnapshotScoring::statusFromOperationalPressure($score),
                "Failed for operational pressure {$score}%",
            );
        }
    }

    public function test_boundary_values(): void
    {
        $this->assertSame(
            PlatformHealthStatus::Healthy,
            OperationsSnapshotScoring::statusFromOperationalPressure(0.0),
        );
        $this->assertSame(
            PlatformHealthStatus::Healthy,
            OperationsSnapshotScoring::statusFromOperationalPressure(14.9),
        );
        $this->assertSame(
            PlatformHealthStatus::Warning,
            OperationsSnapshotScoring::statusFromOperationalPressure(15.0),
        );
        $this->assertSame(
            PlatformHealthStatus::Warning,
            OperationsSnapshotScoring::statusFromOperationalPressure(29.9),
        );
        $this->assertSame(
            PlatformHealthStatus::Critical,
            OperationsSnapshotScoring::statusFromOperationalPressure(30.0),
        );
    }

    public function test_single_critical_card_on_eight_card_grid_is_healthy_not_zone_critical(): void
    {
        $cards = [
            $this->card(PlatformHealthStatus::Critical),
            ...array_fill(0, 7, $this->card(PlatformHealthStatus::Healthy)),
        ];

        $this->assertSame(12.5, OperationsSnapshotScoring::operationalPressurePercent($cards));
        $this->assertSame(PlatformHealthStatus::Healthy, OperationsSnapshotScoring::aggregateStatus($cards));
    }

    public function test_one_critical_and_one_warning_yields_eighteen_percent_warning(): void
    {
        $cards = [
            $this->card(PlatformHealthStatus::Critical),
            $this->card(PlatformHealthStatus::Warning),
            ...array_fill(0, 6, $this->card(PlatformHealthStatus::Healthy)),
        ];

        $pressure = OperationsSnapshotScoring::operationalPressurePercent($cards);
        $this->assertEqualsWithDelta(18.8, $pressure, 0.2);
        $this->assertSame(PlatformHealthStatus::Warning, OperationsSnapshotScoring::aggregateStatus($cards));
    }

    public function test_two_critical_cards_yield_thirty_percent_critical(): void
    {
        $cards = [
            $this->card(PlatformHealthStatus::Critical),
            $this->card(PlatformHealthStatus::Critical),
            ...array_fill(0, 6, $this->card(PlatformHealthStatus::Healthy)),
        ];

        $this->assertSame(25.0, OperationsSnapshotScoring::operationalPressurePercent($cards));
        $this->assertSame(PlatformHealthStatus::Warning, OperationsSnapshotScoring::aggregateStatus($cards));
    }

    public function test_two_critical_and_one_warning_crosses_critical_threshold(): void
    {
        $cards = [
            $this->card(PlatformHealthStatus::Critical),
            $this->card(PlatformHealthStatus::Critical),
            $this->card(PlatformHealthStatus::Warning),
            ...array_fill(0, 5, $this->card(PlatformHealthStatus::Healthy)),
        ];

        $pressure = OperationsSnapshotScoring::operationalPressurePercent($cards);
        $this->assertEqualsWithDelta(31.3, $pressure, 0.2);
        $this->assertSame(PlatformHealthStatus::Critical, OperationsSnapshotScoring::aggregateStatus($cards));
    }

    public function test_empty_cards_remain_disabled(): void
    {
        $this->assertSame(PlatformHealthStatus::Disabled, OperationsSnapshotScoring::aggregateStatus([]));
    }

    private function card(PlatformHealthStatus $status): PlatformCardPayload
    {
        return new PlatformCardPayload(
            key: 'exec_test',
            title: 'Test KPI',
            section: 'executive',
            status: $status,
            generatedAt: Carbon::parse('2026-08-05 10:00:00', 'Asia/Kolkata'),
        );
    }
}
