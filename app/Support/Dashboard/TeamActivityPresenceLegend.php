<?php

namespace App\Support\Dashboard;

/**
 * Presentation-only Presence abbreviation legend for Team Activity.
 * Does not affect status resolution, attendance, or presence calculations.
 */
class TeamActivityPresenceLegend
{
    /**
     * @return list<array{abbr: string, label: string, future?: bool}>
     */
    public static function entries(): array
    {
        return [
            ['abbr' => 'A', 'label' => 'Active'],
            ['abbr' => 'I', 'label' => 'Idle'],
            ['abbr' => 'P', 'label' => 'Pending'],
            ['abbr' => 'ALO', 'label' => 'Auto Logged Out'],
            ['abbr' => 'LV', 'label' => 'On Leave'],
            ['abbr' => 'NLI', 'label' => 'Not Logged In'],
            ['abbr' => 'SNS', 'label' => 'Shift Not Started'],
            ['abbr' => 'NS', 'label' => 'No Schedule'],
            ['abbr' => 'L', 'label' => 'Late'],
            ['abbr' => 'OT', 'label' => 'Overtime', 'future' => true],
            ['abbr' => 'WFH', 'label' => 'Work From Home', 'future' => true],
        ];
    }
}
