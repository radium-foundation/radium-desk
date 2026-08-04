<?php

namespace Tests\Unit\Dashboard;

use App\Data\TeamActivityAgentRow;
use App\Enums\TeamActivityStatus;
use App\Support\Dashboard\TeamActivityMemberStatusPresenter;
use Tests\TestCase;

class TeamActivityMemberStatusPresenterTest extends TestCase
{
    public function test_context_label_uses_current_duration_for_active_states(): void
    {
        $presenter = app(TeamActivityMemberStatusPresenter::class);
        $agent = $this->agentRow(TeamActivityStatus::Working, currentDurationLabel: '18m');

        $this->assertSame('18m', $presenter->contextLabel($agent, '5m'));
    }

    public function test_status_codes_are_compact_operational_abbreviations(): void
    {
        $presenter = app(TeamActivityMemberStatusPresenter::class);

        $this->assertSame('A', $presenter->statusCode($this->agentRow(TeamActivityStatus::Working)));
        $this->assertSame('I', $presenter->statusCode($this->agentRow(TeamActivityStatus::Idle)));
        $this->assertSame('P', $presenter->statusCode($this->agentRow(TeamActivityStatus::WaitingCustomer)));
        $this->assertSame('B', $presenter->statusCode($this->agentRow(TeamActivityStatus::Ira)));
        $this->assertSame('ALO', $presenter->statusCode($this->agentRow(TeamActivityStatus::AutoLogout)));
        $this->assertSame('LV', $presenter->statusCode($this->agentRow(TeamActivityStatus::Leave)));
        $this->assertSame('NLI', $presenter->statusCode($this->agentRow(TeamActivityStatus::NotLoggedIn)));
        $this->assertSame('SNS', $presenter->statusCode($this->agentRow(TeamActivityStatus::NotStartedShift)));
        $this->assertSame('NS', $presenter->statusCode($this->agentRow(TeamActivityStatus::NoSchedule)));
    }

    public function test_state_duration_merges_into_active_and_idle_codes(): void
    {
        $presenter = app(TeamActivityMemberStatusPresenter::class);

        $active = $this->agentRow(TeamActivityStatus::Working, currentDurationLabel: '1h 34m');
        $idle = $this->agentRow(TeamActivityStatus::Idle, currentDurationLabel: '18m');
        $pending = $this->agentRow(TeamActivityStatus::WaitingCustomer, currentDurationLabel: '12m');
        $leave = $this->agentRow(TeamActivityStatus::Leave, workingLabel: 'Annual Leave');

        $this->assertSame('1h 34m', $presenter->stateDurationLabel($active, '5m'));
        $this->assertSame('18m', $presenter->stateDurationLabel($idle, null));
        $this->assertSame('10s', $presenter->stateDurationLabel($pending, '10s'));
        $this->assertNull($presenter->stateDurationLabel($leave, null));
    }

    public function test_presence_aria_label_uses_full_words_with_merged_duration(): void
    {
        $presenter = app(TeamActivityMemberStatusPresenter::class);
        $agent = $this->agentRow(
            TeamActivityStatus::Working,
            currentDurationLabel: '37m',
            statusLabel: 'Active',
            minutesLate: 33,
        );

        $this->assertSame('33m', $presenter->lateDurationLabel($agent));
        $this->assertSame('Active · 37m · Late 33m', $presenter->presenceAriaLabel($agent, null));
    }

    public function test_idle_presence_aria_label_includes_late(): void
    {
        $presenter = app(TeamActivityMemberStatusPresenter::class);
        $agent = $this->agentRow(
            TeamActivityStatus::Idle,
            currentDurationLabel: '15m',
            statusLabel: 'Idle',
            minutesLate: 8,
        );

        $this->assertSame('Idle · 15m · Late 8m', $presenter->presenceAriaLabel($agent, null));
    }

    public function test_late_indicator_suppressed_for_leave_and_non_late(): void
    {
        $presenter = app(TeamActivityMemberStatusPresenter::class);

        $leave = $this->agentRow(
            TeamActivityStatus::Leave,
            workingLabel: 'Annual Leave',
            statusLabel: 'On Leave',
            minutesLate: 20,
        );
        $onTime = $this->agentRow(
            TeamActivityStatus::Working,
            currentDurationLabel: '12m',
            statusLabel: 'Active',
            minutesLate: null,
        );

        $this->assertNull($presenter->lateDurationLabel($leave));
        $this->assertSame('On Leave · Annual Leave', $presenter->presenceAriaLabel($leave, null));
        $this->assertNull($presenter->lateDurationLabel($onTime));
        $this->assertSame('Active · 12m', $presenter->presenceAriaLabel($onTime, null));
    }

    public function test_normalize_duration_formats_compact_presence_values(): void
    {
        $presenter = app(TeamActivityMemberStatusPresenter::class);

        $this->assertSame('18m', $presenter->normalizeDuration('18 min'));
        $this->assertSame('45m', $presenter->normalizeDuration('45m'));
        $this->assertSame('2h', $presenter->normalizeDuration('2h'));
        $this->assertSame('1h 15m', $presenter->normalizeDuration('1h 15m'));
        $this->assertNull($presenter->normalizeDuration('—'));
    }

    private function agentRow(
        TeamActivityStatus $status,
        ?string $currentDurationLabel = null,
        ?string $workingLabel = null,
        string $statusLabel = 'Active',
        ?int $minutesLate = null,
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
            minutesLate: $minutesLate,
        );
    }
}
