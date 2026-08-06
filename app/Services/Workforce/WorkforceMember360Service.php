<?php

namespace App\Services\Workforce;

use App\Data\Workforce\AttendanceMatrixCell;
use App\Data\Workforce\AttendanceMatrixMemberRow;
use App\Data\Workforce\WorkforceMember360Action;
use App\Data\Workforce\WorkforceMember360AttendanceSummary;
use App\Data\Workforce\WorkforceMember360Header;
use App\Data\Workforce\WorkforceMember360LeaveItem;
use App\Data\Workforce\WorkforceMember360LeaveSection;
use App\Data\Workforce\WorkforceMember360Profile;
use App\Data\Workforce\WorkforceMember360TimelineDay;
use App\Data\Workforce\WorkforceMember360Trends;
use App\Enums\AttendanceMatrixCellKind;
use App\Enums\LeaveDuration;
use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Operations\OperationsRoleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class WorkforceMember360Service
{
    public function __construct(
        private readonly DailyWorkforceEngine $workforceEngine,
        private readonly OperationsRoleService $roleService,
    ) {}

    public function build(
        User $user,
        ?Carbon $month = null,
        ?string $focusedDay = null,
        ?Carbon $at = null,
    ): WorkforceMember360Profile {
        $user->loadMissing('roles');

        $at ??= now();
        $month = ($month ?? $at->copy())->copy()->startOfMonth();
        $memberRow = $this->workforceEngine->month($user, $month, $at)->memberRow();
        $normalizedFocus = $this->normalizeFocusedDay($focusedDay, $month, $memberRow);

        return new WorkforceMember360Profile(
            header: $this->buildHeader($user),
            attendance: $this->buildAttendanceSummary($memberRow, $month),
            leave: $this->buildLeaveSection($user),
            timeline: $this->buildTimeline($memberRow, $normalizedFocus),
            trends: $this->buildTrends($memberRow),
            actions: $this->buildActions($user, $month),
            tabs: $this->tabs(),
            activeTab: 'overview',
            focusedDay: $normalizedFocus,
            profileUrl: route('workforce-management.members.show', [
                'user' => $user->id,
                'month' => $month->format('Y-m'),
                'day' => $normalizedFocus,
            ]),
        );
    }

    /**
     * Attendance % = present / (present + absent + late).
     * Aligns with matrix countable working outcomes for the selected month.
     */
    public function attendancePercent(int $presentDays, int $absentDays, int $lateDays): float
    {
        $denominator = $presentDays + $absentDays + $lateDays;

        if ($denominator <= 0) {
            return 0.0;
        }

        return round(($presentDays / $denominator) * 100, 1);
    }

    /**
     * @return list<array{date: string, value: int, label: string}>
     */
    public function attendanceTrendSeries(AttendanceMatrixMemberRow $memberRow): array
    {
        $series = [];

        foreach ($memberRow->cells as $date => $cell) {
            $series[] = [
                'date' => $date,
                'value' => match ($cell->kind) {
                    AttendanceMatrixCellKind::Present => 2,
                    AttendanceMatrixCellKind::Late => 1,
                    AttendanceMatrixCellKind::HalfDay => 1,
                    AttendanceMatrixCellKind::Absent,
                    AttendanceMatrixCellKind::ShortAttendance => 0,
                    AttendanceMatrixCellKind::Leave => 0,
                    default => -1,
                },
                'label' => $cell->kind->label(),
            ];
        }

        return $series;
    }

    /**
     * @return list<array{date: string, value: int, label: string}>
     */
    public function lateTrendSeries(AttendanceMatrixMemberRow $memberRow): array
    {
        $series = [];

        foreach ($memberRow->cells as $date => $cell) {
            $series[] = [
                'date' => $date,
                'value' => $cell->kind === AttendanceMatrixCellKind::Late ? 1 : 0,
                'label' => $cell->kind->label(),
            ];
        }

        return $series;
    }

    /**
     * @return list<array{date: string, value: int, label: string}>
     */
    public function otTrendSeries(AttendanceMatrixMemberRow $memberRow): array
    {
        $series = [];

        foreach ($memberRow->cells as $date => $cell) {
            $seconds = (int) ($cell->drawerPayload['overtime_seconds'] ?? 0);
            $series[] = [
                'date' => $date,
                'value' => $seconds,
                'label' => $seconds > 0 ? (string) $seconds : '0',
            ];
        }

        return $series;
    }

    private function buildHeader(User $user): WorkforceMember360Header
    {
        return new WorkforceMember360Header(
            userId: $user->id,
            name: (string) $user->name,
            initials: $user->initials(),
            roleLabel: $this->roleService->displayLabel($user->roles->first()?->name) ?: null,
            isActive: (bool) $user->is_active,
            employmentStatusLabel: $user->is_active ? 'Active' : 'Inactive',
            teamLabel: null,
            joiningDateLabel: null,
            hasPhoto: false,
        );
    }

    private function buildAttendanceSummary(
        AttendanceMatrixMemberRow $memberRow,
        Carbon $month,
    ): WorkforceMember360AttendanceSummary {
        $summary = $memberRow->summary;
        $percent = $this->attendancePercent(
            $summary->presentDays,
            $summary->absentDays,
            $summary->lateDays,
        );
        $denominator = $summary->presentDays + $summary->absentDays + $summary->lateDays;

        return new WorkforceMember360AttendanceSummary(
            monthLabel: $month->format('F Y'),
            monthValue: $month->format('Y-m'),
            attendancePercent: $percent,
            attendancePercentLabel: $denominator > 0 ? rtrim(rtrim(number_format($percent, 1), '0'), '.').'%' : '—',
            presentDays: $summary->presentDays,
            halfDayDays: $summary->halfDayDays,
            absentDays: $summary->absentDays,
            leaveDays: $summary->leaveDays,
            lateDays: $summary->lateDays,
            extraDays: $summary->extraDays,
            payableDays: $summary->payableDays,
            overtimeLabel: $summary->overtimeLabel,
            hoursLabel: $summary->hoursLabel,
            activeDurationSeconds: $summary->activeDurationSeconds,
            overtimeSeconds: $summary->overtimeSeconds,
            denominatorDays: $denominator,
        );
    }

    private function buildLeaveSection(User $user): WorkforceMember360LeaveSection
    {
        $today = now()->copy()->startOfDay();

        $upcoming = LeaveRequest::query()
            ->where('user_id', $user->id)
            ->where('status', LeaveRequestStatus::Approved)
            ->whereDate('end_date', '>=', $today->toDateString())
            ->orderBy('start_date')
            ->limit(5)
            ->get();

        $history = LeaveRequest::query()
            ->where('user_id', $user->id)
            ->orderByDesc('start_date')
            ->limit(8)
            ->get();

        return new WorkforceMember360LeaveSection(
            balanceAvailable: false,
            balanceNote: 'Leave balance is not configured yet.',
            upcoming: $this->mapLeaveItems($upcoming),
            history: $this->mapLeaveItems($history),
        );
    }

    /**
     * @param  Collection<int, LeaveRequest>  $requests
     * @return list<WorkforceMember360LeaveItem>
     */
    private function mapLeaveItems(Collection $requests): array
    {
        return $requests
            ->map(function (LeaveRequest $request): WorkforceMember360LeaveItem {
                $start = $request->start_date->toDateString();
                $end = $request->end_date->toDateString();

                return new WorkforceMember360LeaveItem(
                    id: $request->id,
                    startDate: $start,
                    endDate: $end,
                    dateRangeLabel: $start === $end
                        ? $request->start_date->format('M j, Y')
                        : $request->start_date->format('M j').' – '.$request->end_date->format('M j, Y'),
                    status: $request->status->value,
                    statusLabel: $request->status->label(),
                    duration: $request->duration?->value ?? LeaveDuration::FullDay->value,
                    durationLabel: $request->duration?->label() ?? LeaveDuration::FullDay->label(),
                    reason: (string) $request->reason,
                    url: route('leave-requests.show', $request),
                );
            })
            ->values()
            ->all();
    }

    /**
     * @return list<WorkforceMember360TimelineDay>
     */
    private function buildTimeline(AttendanceMatrixMemberRow $memberRow, ?string $focusedDay): array
    {
        $days = [];

        foreach ($memberRow->cells as $date => $cell) {
            /** @var AttendanceMatrixCell $cell */
            $loginAt = $cell->drawerPayload['first_login_at'] ?? null;
            $logoutAt = $cell->drawerPayload['last_logout_at'] ?? null;
            $activeSeconds = (int) ($cell->drawerPayload['active_duration_seconds'] ?? 0);
            $minutesLate = $cell->drawerPayload['minutes_late'] ?? null;

            $days[] = new WorkforceMember360TimelineDay(
                workDate: $date,
                dayLabel: Carbon::parse($date)->format('D, M j'),
                kind: $cell->kind,
                kindLabel: $cell->kind->label(),
                tone: $cell->tone,
                loginLabel: is_string($loginAt) && $loginAt !== ''
                    ? Carbon::parse($loginAt)->format('H:i')
                    : null,
                logoutLabel: is_string($logoutAt) && $logoutAt !== ''
                    ? Carbon::parse($logoutAt)->format('H:i')
                    : null,
                hoursLabel: $activeSeconds > 0
                    ? $this->formatDuration($activeSeconds)
                    : null,
                minutesLate: is_numeric($minutesLate) ? (int) $minutesLate : null,
                isFocused: $focusedDay !== null && $focusedDay === $date,
                isFuture: $cell->kind === AttendanceMatrixCellKind::Future,
            );
        }

        return $days;
    }

    private function buildTrends(AttendanceMatrixMemberRow $memberRow): WorkforceMember360Trends
    {
        return new WorkforceMember360Trends(
            attendanceSeries: $this->attendanceTrendSeries($memberRow),
            lateSeries: $this->lateTrendSeries($memberRow),
            otSeries: $this->otTrendSeries($memberRow),
        );
    }

    /**
     * @return list<WorkforceMember360Action>
     */
    private function buildActions(User $user, Carbon $month): array
    {
        return [
            new WorkforceMember360Action(
                key: 'attendance_history',
                label: 'Attendance History',
                url: '#wm360-timeline',
                enabled: true,
                soon: false,
            ),
            new WorkforceMember360Action(
                key: 'view_leave',
                label: 'View Leave',
                url: route('leave-requests.index'),
                enabled: true,
                soon: false,
            ),
            new WorkforceMember360Action(
                key: 'performance',
                label: 'Performance',
                url: route('admin.workforce.performance.index', [
                    'period' => 'this_month',
                ]),
                enabled: true,
                soon: false,
            ),
            new WorkforceMember360Action(
                key: 'work_recognition',
                label: 'Work Recognition',
                url: (config('workforce_recognition.enabled') && auth()->user()?->can('workforce.recognition.view'))
                    ? route('workforce-management.recognition.index', [
                        'month' => $month->format('Y-m'),
                        'user_id' => $user->id,
                    ])
                    : null,
                enabled: (bool) config('workforce_recognition.enabled')
                    && (auth()->user()?->can('workforce.recognition.view') ?? false),
                soon: ! config('workforce_recognition.enabled'),
            ),
            new WorkforceMember360Action(
                key: 'payroll',
                label: 'Payroll',
                url: null,
                enabled: false,
                soon: true,
            ),
        ];
    }

    /**
     * @return list<array{key: string, label: string, enabled: bool}>
     */
    private function tabs(): array
    {
        return [
            ['key' => 'overview', 'label' => 'Overview', 'enabled' => true],
            ['key' => 'attendance', 'label' => 'Attendance', 'enabled' => false],
            ['key' => 'leave', 'label' => 'Leave', 'enabled' => false],
            ['key' => 'performance', 'label' => 'Performance', 'enabled' => false],
            ['key' => 'payroll', 'label' => 'Payroll', 'enabled' => false],
        ];
    }

    private function normalizeFocusedDay(
        ?string $focusedDay,
        Carbon $month,
        AttendanceMatrixMemberRow $memberRow,
    ): ?string {
        if ($focusedDay === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $focusedDay) !== 1) {
            return null;
        }

        $day = Carbon::createFromFormat('Y-m-d', $focusedDay)->startOfDay();

        if (! $day->isSameMonth($month)) {
            return null;
        }

        return isset($memberRow->cells[$focusedDay]) ? $focusedDay : null;
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0m';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        return sprintf('%dm', max(1, $minutes));
    }
}
