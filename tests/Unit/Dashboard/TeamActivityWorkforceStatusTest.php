<?php

namespace Tests\Unit\Dashboard;

use App\Enums\TeamActivityStatus;
use App\Support\Dashboard\TeamActivityWorkforceStatus;
use Tests\TestCase;

class TeamActivityWorkforceStatusTest extends TestCase
{
    public function test_maps_internal_states_to_workforce_labels(): void
    {
        $this->assertSame('Active', TeamActivityWorkforceStatus::labelFor(TeamActivityStatus::Working));
        $this->assertSame('Idle', TeamActivityWorkforceStatus::labelFor(TeamActivityStatus::Idle));
        $this->assertSame('Busy', TeamActivityWorkforceStatus::labelFor(TeamActivityStatus::OnIvr));
        $this->assertSame('Pending', TeamActivityWorkforceStatus::labelFor(TeamActivityStatus::WaitingCustomer));
        $this->assertSame('On Break', TeamActivityWorkforceStatus::labelFor(TeamActivityStatus::Break));
        $this->assertSame('On Leave', TeamActivityWorkforceStatus::labelFor(TeamActivityStatus::Leave));
        $this->assertSame('Shift Ended', TeamActivityWorkforceStatus::labelFor(TeamActivityStatus::OffDuty));
        $this->assertSame('Shift Not Started', TeamActivityWorkforceStatus::labelFor(TeamActivityStatus::NotStartedShift));
    }

    public function test_maps_ira_automation_states_to_workforce_labels(): void
    {
        $this->assertSame('Busy', TeamActivityWorkforceStatus::labelForIraAutomationState('Processing'));
        $this->assertSame('Pending', TeamActivityWorkforceStatus::labelForIraAutomationState('Waiting RadiumBox'));
        $this->assertSame('Idle', TeamActivityWorkforceStatus::labelForIraAutomationState('Idle'));
    }
}
