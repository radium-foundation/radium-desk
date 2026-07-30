@props(['report'])

@php
    /** @var \App\Data\Workforce\AttendanceMatrixReport $report */
@endphp

<div class="attendance-matrix-scroll" data-attendance-matrix>
    <table class="table attendance-matrix-table mb-0">
        <thead>
            <tr>
                <th scope="col" class="attendance-matrix-sticky-col attendance-matrix-employee-col">Employee</th>
                @foreach($report->days as $day)
                    <th
                        scope="col"
                        @class([
                            'attendance-matrix-day-col',
                            'text-center',
                            'attendance-matrix-day-col--weekend' => $day->isWeekend && ! $day->isHoliday,
                            'attendance-matrix-day-col--holiday' => $day->isHoliday,
                            'attendance-matrix-day-col--future' => $day->isFuture,
                        ])
                        title="{{ $day->date->format('D, M j Y') }}{{ $day->holidayName ? ' · '.$day->holidayName : '' }}"
                    >
                        <span class="attendance-matrix-day-num">{{ $day->dayNumber }}</span>
                        <span class="attendance-matrix-day-wd">{{ $day->weekdayLabel }}</span>
                    </th>
                @endforeach
                <th scope="col" class="attendance-matrix-summary-col attendance-matrix-summary-col--first text-center">Present</th>
                <th scope="col" class="attendance-matrix-summary-col text-center">Absent</th>
                <th scope="col" class="attendance-matrix-summary-col text-center">Leave</th>
                <th scope="col" class="attendance-matrix-summary-col text-center">Late</th>
                <th scope="col" class="attendance-matrix-summary-col text-center">Hours</th>
                <th scope="col" class="attendance-matrix-summary-col text-center">OT</th>
            </tr>
        </thead>
        <tbody>
            @forelse($report->members as $member)
                @php
                    $initials = collect(preg_split('/\s+/', trim($member->name)))
                        ->filter()
                        ->take(2)
                        ->map(fn (string $part): string => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($part, 0, 1)))
                        ->implode('');
                @endphp
                <tr
                    data-attendance-row
                    data-employee-name="{{ Str::lower($member->name) }}"
                >
                    <th scope="row" class="attendance-matrix-sticky-col attendance-matrix-employee-col">
                        <button
                            type="button"
                            class="attendance-matrix-employee attendance-matrix-employee--button"
                            data-wm360-open-member
                            data-user-id="{{ $member->userId }}"
                            aria-label="Open Workforce Member 360 for {{ $member->name }}"
                        >
                            <span class="attendance-matrix-employee__avatar" aria-hidden="true">{{ $initials !== '' ? $initials : '?' }}</span>
                            <span class="attendance-matrix-employee__text">
                                <span class="attendance-matrix-employee__name text-truncate" title="{{ $member->name }}">{{ $member->name }}</span>
                                @if($member->roleLabel)
                                    <span class="attendance-matrix-employee__role text-truncate">{{ $member->roleLabel }}</span>
                                @endif
                            </span>
                        </button>
                    </th>
                    @foreach($report->days as $day)
                        @php
                            $dateKey = $day->date->toDateString();
                            $cell = $member->cells[$dateKey] ?? null;
                        @endphp
                        <td
                            @class([
                                'attendance-matrix-day-cell text-center',
                                'attendance-matrix-day-col--weekend' => $day->isWeekend && ! $day->isHoliday,
                                'attendance-matrix-day-col--holiday' => $day->isHoliday,
                            ])
                        >
                            @if($cell)
                                @include('workforce-management.partials.attendance-cell', [
                                    'cell' => $cell,
                                    'columnMuted' => $day->isWeekend || $day->isHoliday,
                                ])
                            @endif
                        </td>
                    @endforeach
                    <td class="text-center attendance-matrix-summary-col attendance-matrix-summary-col--first">{{ $member->summary->presentDays }}</td>
                    <td class="text-center attendance-matrix-summary-col">{{ $member->summary->absentDays }}</td>
                    <td class="text-center attendance-matrix-summary-col">{{ $member->summary->leaveDays }}</td>
                    <td class="text-center attendance-matrix-summary-col">{{ $member->summary->lateDays }}</td>
                    <td class="text-center attendance-matrix-summary-col text-nowrap">{{ $member->summary->hoursLabel }}</td>
                    <td class="text-center attendance-matrix-summary-col text-nowrap">{{ $member->summary->overtimeLabel }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($report->days) + 7 }}" class="text-muted text-center py-5">
                        No attendance-tracked team members found.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
