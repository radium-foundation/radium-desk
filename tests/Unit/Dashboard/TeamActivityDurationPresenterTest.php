<?php

namespace Tests\Unit\Dashboard;

use App\Support\Dashboard\TeamActivityDurationPresenter;
use Tests\TestCase;

class TeamActivityDurationPresenterTest extends TestCase
{
    public function test_compacts_long_duration_units(): void
    {
        $presenter = new TeamActivityDurationPresenter;

        $this->assertSame('11m', $presenter->compact('11 min'));
        $this->assertSame('3h 13m', $presenter->compact('3 hr 13 min'));
        $this->assertSame('29s', $presenter->compact('29 sec'));
        $this->assertSame('1h', $presenter->compact('1 hr'));
    }

    public function test_splits_duration_into_value_and_unit_parts(): void
    {
        $presenter = new TeamActivityDurationPresenter;

        $this->assertSame([
            ['value' => '3', 'unit' => 'h'],
            ['value' => '13', 'unit' => 'm'],
        ], $presenter->parts('3h 13m'));

        $this->assertSame([
            ['value' => '11', 'unit' => 'm'],
        ], $presenter->parts('11m'));
    }

    public function test_non_duration_labels_are_not_treated_as_durations(): void
    {
        $presenter = new TeamActivityDurationPresenter;

        $this->assertFalse($presenter->isDuration('Annual Leave'));
        $this->assertSame([], $presenter->parts('Annual Leave'));
    }
}
