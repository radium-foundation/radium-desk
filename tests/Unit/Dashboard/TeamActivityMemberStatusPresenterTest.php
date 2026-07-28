<?php

namespace Tests\Unit\Dashboard;

use App\Data\TeamActivityAgentRow;
use App\Enums\TeamActivityStatus;
use App\Support\Dashboard\TeamActivityMemberStatusPresenter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TeamActivityMemberStatusPresenterTest extends TestCase
{
    public function test_context_label_uses_current_duration_for_active_states(): void
    {
        $presenter = new TeamActivityMemberStatusPresenter;
        $agent = $this->agentRow(TeamActivityStatus::Working, currentDurationLabel: '18m');

        $this->assertSame('18 min', $presenter->contextLabel($agent, '5 min'));
    }

    public function test_context_label_uses_leave_reason_for_on_leave(): void
    {
        $presenter = new TeamActivityMemberStatusPresenter;
        $agent = $this->agentRow(TeamActivityStatus::Leave, workingLabel: 'Annual Leave');

        $this->assertSame('Annual Leave', $presenter->contextLabel($agent, null));
    }

    public function test_context_label_is_null_for_offline(): void
    {
        $presenter = new TeamActivityMemberStatusPresenter;
        $agent = $this->agentRow(TeamActivityStatus::Offline);

        $this->assertNull($presenter->contextLabel($agent, '12 min'));
    }

    public function test_aria_label_joins_status_and_context(): void
    {
        $presenter = new TeamActivityMemberStatusPresenter;
        $agent = $this->agentRow(TeamActivityStatus::Working, currentDurationLabel: '4m', statusLabel: 'Active');

        $this->assertSame('Active · 4 min', $presenter->ariaLabel($agent, null));
    }

    public function test_normalize_duration_formats_compact_presence_values(): void
    {
        $presenter = new TeamActivityMemberStatusPresenter;

        $this->assertSame('18 min', $presenter->normalizeDuration('18 min'));
        $this->assertSame('45 min', $presenter->normalizeDuration('45m'));
        $this->assertSame('2 hr', $presenter->normalizeDuration('2h'));
        $this->assertSame('1 hr 15 min', $presenter->normalizeDuration('1h 15m'));
        $this->assertNull($presenter->normalizeDuration('—'));
    }

    private function agentRow(
        TeamActivityStatus $status,
        ?string $currentDurationLabel = null,
        ?string $workingLabel = null,
        string $statusLabel = 'Active',
    ): TeamActivityAgentRow {
        return new TeamActivityAgentRow(
            id: 1,
            name: 'Test Agent',
            status: $status,
            statusLabel: $statusLabel,
            statusTone: 'available',
            workingLabel: $workingLabel,
            overtimeLabel: null,
            todayCount: 0,
            latest: null,
            history: [],
            expanded: false,
            isVirtual: false,
            currentDurationLabel: $currentDurationLabel,
        );
    }
}
