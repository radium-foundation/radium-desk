<?php

namespace Tests\Unit\DashboardOemHandoff;

use Tests\TestCase;

class DashboardOemHandoffConfigTest extends TestCase
{
    public function test_feature_flags_default_off(): void
    {
        $this->assertFalse(config('dashboard_oem_handoff.enabled'));
        $this->assertFalse(config('dashboard_oem_handoff.mantra_result_capture_enabled'));
        $this->assertFalse(config('dashboard_oem_handoff.prune_enabled'));
        $this->assertSame(24, config('dashboard_oem_handoff.prune_grace_hours_after_expiry'));
    }
}
