<?php

namespace Tests\Unit\Dashboard;

use App\Support\Dashboard\TeamActivityPresenceLegend;
use Tests\TestCase;

class TeamActivityPresenceLegendTest extends TestCase
{
    public function test_entries_cover_current_and_future_abbreviations(): void
    {
        $entries = TeamActivityPresenceLegend::entries();
        $byAbbr = collect($entries)->keyBy('abbr');

        $this->assertSame('Active', $byAbbr['A']['label']);
        $this->assertSame('Idle', $byAbbr['I']['label']);
        $this->assertSame('Pending', $byAbbr['P']['label']);
        $this->assertSame('Auto Logged Out', $byAbbr['ALO']['label']);
        $this->assertSame('On Leave', $byAbbr['LV']['label']);
        $this->assertSame('Not Logged In', $byAbbr['NLI']['label']);
        $this->assertSame('Shift Not Started', $byAbbr['SNS']['label']);
        $this->assertSame('No Schedule', $byAbbr['NS']['label']);
        $this->assertSame('Late', $byAbbr['L']['label']);
        $this->assertTrue($byAbbr['OT']['future'] ?? false);
        $this->assertTrue($byAbbr['WFH']['future'] ?? false);
        $this->assertSame('Overtime', $byAbbr['OT']['label']);
        $this->assertSame('Work From Home', $byAbbr['WFH']['label']);
    }
}
