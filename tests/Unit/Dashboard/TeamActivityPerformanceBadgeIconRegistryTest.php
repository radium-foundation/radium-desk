<?php

namespace Tests\Unit\Dashboard;

use App\Support\Dashboard\TeamActivityPerformanceBadgeIconRegistry;
use Tests\TestCase;

class TeamActivityPerformanceBadgeIconRegistryTest extends TestCase
{
    public function test_maps_badge_keys_to_settings_icons(): void
    {
        $map = TeamActivityPerformanceBadgeIconRegistry::map();

        $this->assertSame('sparkles', $map['extra_contribution']);
        $this->assertSame('zap', $map['exceptional_day']);
        $this->assertSame('alert-triangle', $map['critical_work']);
        $this->assertSame('heart-pulse', $map['team_helper']);
    }

    public function test_renders_outline_svg_for_extra_contribution(): void
    {
        $html = TeamActivityPerformanceBadgeIconRegistry::render('extra_contribution');

        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('team-activity-performance-badge__icon', $html);
        $this->assertStringContainsString('m12 3-1.912 5.813', $html);
        $this->assertStringNotContainsString('🌙', $html);
    }

    public function test_unknown_badge_key_falls_back_to_circle_icon(): void
    {
        $this->assertSame('circle', TeamActivityPerformanceBadgeIconRegistry::iconNameFor('unknown_badge'));
    }
}
