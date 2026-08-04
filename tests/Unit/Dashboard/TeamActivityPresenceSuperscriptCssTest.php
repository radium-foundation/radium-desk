<?php

namespace Tests\Unit\Dashboard;

use Tests\TestCase;

class TeamActivityPresenceSuperscriptCssTest extends TestCase
{
    public function test_compact_presence_css_keeps_superscripts_unclipped(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertNotFalse($css);
        $this->assertStringContainsString('.team-activity-live-presence--compact', $css);
        $this->assertStringContainsString('overflow: visible;', $css);
        $this->assertStringContainsString('top: -0.4em;', $css);
        $this->assertStringContainsString('.team-activity-live-presence__primary.team-activity-status-pill', $css);
        $this->assertStringContainsString('align-items: baseline;', $css);

        // Regression: the clipped rules must not remain on the compact wrapper.
        $this->assertDoesNotMatchRegularExpression(
            '/\.team-activity-live-presence--compact\s*\{[^}]*overflow:\s*hidden;/s',
            $css,
        );
    }
}
