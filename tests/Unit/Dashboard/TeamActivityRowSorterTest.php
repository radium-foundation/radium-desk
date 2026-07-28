<?php

namespace Tests\Unit\Dashboard;

use App\Data\TeamActivityAgentRow;
use App\Data\TeamActivityEntry;
use App\Enums\TeamActivityStatus;
use App\Support\Dashboard\TeamActivityRowSorter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TeamActivityRowSorterTest extends TestCase
{
    private TeamActivityRowSorter $sorter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sorter = new TeamActivityRowSorter;
    }

    public function test_sorts_by_latest_activity_descending(): void
    {
        $recent = $this->humanRow('Shipra', TeamActivityStatus::Working, now()->subMinutes(2));
        $older = $this->humanRow('Rahul', TeamActivityStatus::Working, now()->subMinutes(5));
        $oldest = $this->humanRow('Aman', TeamActivityStatus::Working, now()->subMinutes(22));

        $sorted = $this->sorter->sort([$oldest, $recent, $older]);

        $this->assertSame(['Shipra', 'Rahul', 'Aman'], array_map(static fn ($row) => $row->name, $sorted));
    }

    public function test_users_without_activity_sort_after_active_users(): void
    {
        $active = $this->humanRow('Neha', TeamActivityStatus::Working, now()->subHours(2));
        $inactive = $this->humanRow('No Activity', TeamActivityStatus::Working, null);

        $sorted = $this->sorter->sort([$inactive, $active]);

        $this->assertSame(['Neha', 'No Activity'], array_map(static fn ($row) => $row->name, $sorted));
    }

    public function test_tie_break_prefers_working_users_over_off_duty_users(): void
    {
        $sameTime = now()->subMinutes(10);
        $working = $this->humanRow('Working Agent', TeamActivityStatus::Working, $sameTime);
        $offDuty = $this->humanRow('Off Duty Agent', TeamActivityStatus::OffDuty, $sameTime);

        $sorted = $this->sorter->sort([$offDuty, $working]);

        $this->assertSame(['Working Agent', 'Off Duty Agent'], array_map(static fn ($row) => $row->name, $sorted));
    }

    public function test_tie_break_falls_back_to_alphabetical_name(): void
    {
        $sameTime = now()->subMinutes(3);
        $beta = $this->humanRow('Beta Agent', TeamActivityStatus::Working, $sameTime);
        $alpha = $this->humanRow('Alpha Agent', TeamActivityStatus::Working, $sameTime);

        $sorted = $this->sorter->sort([$beta, $alpha]);

        $this->assertSame(['Alpha Agent', 'Beta Agent'], array_map(static fn ($row) => $row->name, $sorted));
    }

    public function test_ira_is_placed_after_active_humans_and_before_idle_or_off_duty(): void
    {
        $active = $this->humanRow('Active Agent', TeamActivityStatus::Working, now()->subMinute());
        $idle = $this->humanRow('Break Agent', TeamActivityStatus::Break, now()->subMinutes(2));
        $offDuty = $this->humanRow('Off Duty Agent', TeamActivityStatus::OffDuty, now()->subMinutes(3));
        $ira = $this->iraRow();

        $sorted = $this->sorter->sort([$offDuty, $idle, $active], $ira);

        $this->assertSame(
            ['Active Agent', 'IRA', 'Break Agent', 'Off Duty Agent'],
            array_map(static fn ($row) => $row->name, $sorted),
        );
    }

    private function humanRow(string $name, TeamActivityStatus $status, ?Carbon $latestAt): TeamActivityAgentRow
    {
        return new TeamActivityAgentRow(
            id: crc32($name),
            name: $name,
            status: $status,
            statusLabel: $status->label(),
            statusTone: $status->tone(),
            workingLabel: null,
            overtimeLabel: null,
            todayCount: 0,
            latest: $latestAt === null
                ? null
                : new TeamActivityEntry(
                    at: $latestAt,
                    time: $latestAt->format('H:i'),
                    label: 'Assigned',
                    reference: 'RD1000001',
                    incidentId: 1,
                ),
            latestActivityAt: $latestAt,
        );
    }

    private function iraRow(): TeamActivityAgentRow
    {
        return new TeamActivityAgentRow(
            id: 0,
            name: 'IRA',
            status: TeamActivityStatus::Ira,
            statusLabel: 'Busy',
            statusTone: TeamActivityStatus::Ira->tone(),
            workingLabel: null,
            overtimeLabel: null,
            todayCount: 0,
            latest: null,
            isVirtual: true,
            badge: 'AI / Automation',
        );
    }
}
