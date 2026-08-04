<?php

namespace Tests\Unit\Platform;

use App\Data\Platform\PlatformHealthComponent;
use App\Enums\PlatformHealthStatus;
use App\Services\Platform\Health\PlatformHealthSnapshotService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PlatformHealthDisabledAggregationTest extends TestCase
{
    public function test_disabled_queue_and_automation_do_not_degrade_healthy_overall(): void
    {
        $status = PlatformHealthSnapshotService::aggregateOverall([
            $this->healthComponent('scheduler', PlatformHealthStatus::Healthy),
            $this->healthComponent('presence', PlatformHealthStatus::Healthy),
            $this->healthComponent('queue', PlatformHealthStatus::Disabled),
            $this->healthComponent('automation', PlatformHealthStatus::Disabled),
            $this->healthComponent('database', PlatformHealthStatus::Healthy),
            $this->healthComponent('cache', PlatformHealthStatus::Healthy),
            $this->healthComponent('storage', PlatformHealthStatus::Healthy),
        ]);

        $this->assertSame(PlatformHealthStatus::Healthy, $status);
    }

    public function test_disabled_queue_does_not_mask_critical_database(): void
    {
        $status = PlatformHealthSnapshotService::aggregateOverall([
            $this->healthComponent('queue', PlatformHealthStatus::Disabled),
            $this->healthComponent('database', PlatformHealthStatus::Critical),
            $this->healthComponent('scheduler', PlatformHealthStatus::Healthy),
        ]);

        $this->assertSame(PlatformHealthStatus::Critical, $status);
    }

    public function test_automation_warning_with_others_healthy_is_warning(): void
    {
        $status = PlatformHealthSnapshotService::aggregateOverall([
            $this->healthComponent('automation', PlatformHealthStatus::Warning),
            $this->healthComponent('scheduler', PlatformHealthStatus::Healthy),
            $this->healthComponent('queue', PlatformHealthStatus::Healthy),
            $this->healthComponent('database', PlatformHealthStatus::Healthy),
        ]);

        $this->assertSame(PlatformHealthStatus::Warning, $status);
    }

    public function test_all_disabled_falls_back_to_disabled(): void
    {
        $status = PlatformHealthSnapshotService::aggregateOverall([
            $this->healthComponent('queue', PlatformHealthStatus::Disabled),
            $this->healthComponent('automation', PlatformHealthStatus::Disabled),
        ]);

        $this->assertSame(PlatformHealthStatus::Disabled, $status);
    }

    public function test_empty_components_falls_back_to_disabled(): void
    {
        $this->assertSame(
            PlatformHealthStatus::Disabled,
            PlatformHealthSnapshotService::aggregateOverall([]),
        );
    }

    public function test_disabled_components_remain_present_on_probe_cards(): void
    {
        config(['infrastructure.queue_worker_mode' => 'disabled']);

        $components = [
            $this->healthComponent('scheduler', PlatformHealthStatus::Healthy),
            $this->healthComponent('queue', PlatformHealthStatus::Disabled),
            $this->healthComponent('automation', PlatformHealthStatus::Disabled),
            $this->healthComponent('database', PlatformHealthStatus::Healthy),
        ];

        $overall = PlatformHealthSnapshotService::aggregateOverall($components);

        $this->assertSame(PlatformHealthStatus::Healthy, $overall);
        $this->assertSame(PlatformHealthStatus::Disabled, $components[1]->status);
        $this->assertSame(PlatformHealthStatus::Disabled, $components[2]->status);
    }

    private function healthComponent(string $key, PlatformHealthStatus $status): PlatformHealthComponent
    {
        return new PlatformHealthComponent(
            key: $key,
            label: ucfirst($key),
            status: $status,
            detail: $status->label(),
            checkedAt: Carbon::parse('2026-08-04 23:00:00', 'Asia/Kolkata'),
        );
    }
}
