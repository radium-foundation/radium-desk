@props(['profile'])

@php
    /** @var \App\Data\Workforce\WorkforceMember360Profile $profile */
@endphp

<section class="wm360-section" aria-labelledby="wm360-attendance-heading">
    <div class="wm360-section__head">
        <h3 id="wm360-attendance-heading" class="wm360-section__title">Attendance Summary</h3>
        <span class="wm360-section__note">{{ $profile->attendance->monthLabel }}</span>
    </div>

    <div class="wm360-kpi-grid">
        <article class="wm360-kpi wm360-kpi--accent">
            <div class="wm360-kpi__label">Attendance</div>
            <div class="wm360-kpi__value" data-wm360-attendance-percent>{{ $profile->attendance->attendancePercentLabel }}</div>
        </article>
        <article class="wm360-kpi">
            <div class="wm360-kpi__label">Present</div>
            <div class="wm360-kpi__value">{{ $profile->attendance->presentDays }}</div>
        </article>
        <article class="wm360-kpi">
            <div class="wm360-kpi__label">Half Day</div>
            <div class="wm360-kpi__value">{{ $profile->attendance->halfDayDays }}</div>
        </article>
        <article class="wm360-kpi">
            <div class="wm360-kpi__label">Leave</div>
            <div class="wm360-kpi__value">{{ $profile->attendance->leaveDays }}</div>
        </article>
        <article class="wm360-kpi">
            <div class="wm360-kpi__label">Absent</div>
            <div class="wm360-kpi__value">{{ $profile->attendance->absentDays }}</div>
        </article>
        <article class="wm360-kpi">
            <div class="wm360-kpi__label">Extra</div>
            <div class="wm360-kpi__value">{{ $profile->attendance->extraDays }}</div>
        </article>
        <article class="wm360-kpi">
            <div class="wm360-kpi__label">Late</div>
            <div class="wm360-kpi__value">{{ $profile->attendance->lateDays }}</div>
        </article>
        <article class="wm360-kpi">
            <div class="wm360-kpi__label">Payable Days</div>
            <div class="wm360-kpi__value">{{ rtrim(rtrim(number_format($profile->attendance->payableDays, 1), '0'), '.') }}</div>
        </article>
        <article class="wm360-kpi">
            <div class="wm360-kpi__label">OT</div>
            <div class="wm360-kpi__value">{{ $profile->attendance->overtimeLabel }}</div>
        </article>
        <article class="wm360-kpi wm360-kpi--wide">
            <div class="wm360-kpi__label">Working Hours</div>
            <div class="wm360-kpi__value">{{ $profile->attendance->hoursLabel }}</div>
        </article>
    </div>
</section>

<section class="wm360-section" aria-labelledby="wm360-leave-heading">
    <div class="wm360-section__head">
        <h3 id="wm360-leave-heading" class="wm360-section__title">Leave</h3>
    </div>

    <div class="wm360-callout">{{ $profile->leave->balanceNote }}</div>

    <div class="wm360-subsection">
        <h4 class="wm360-subsection__title">Upcoming</h4>
        @forelse($profile->leave->upcoming as $item)
            <a href="{{ $item->url }}" class="wm360-leave-row">
                <span class="wm360-leave-row__dates">{{ $item->dateRangeLabel }}</span>
                <span class="wm360-leave-row__status">{{ $item->statusLabel }} · {{ $item->durationLabel }}</span>
                <span class="wm360-leave-row__reason">{{ \Illuminate\Support\Str::limit($item->reason, 60) }}</span>
            </a>
        @empty
            <p class="wm360-empty">No upcoming leave.</p>
        @endforelse
    </div>

    <div class="wm360-subsection">
        <h4 class="wm360-subsection__title">Recent history</h4>
        @forelse($profile->leave->history as $item)
            <a href="{{ $item->url }}" class="wm360-leave-row">
                <span class="wm360-leave-row__dates">{{ $item->dateRangeLabel }}</span>
                <span class="wm360-leave-row__status">{{ $item->statusLabel }} · {{ $item->durationLabel }}</span>
                <span class="wm360-leave-row__reason">{{ \Illuminate\Support\Str::limit($item->reason, 60) }}</span>
            </a>
        @empty
            <p class="wm360-empty">No leave history.</p>
        @endforelse
    </div>
</section>

<section class="wm360-section" id="wm360-timeline" aria-labelledby="wm360-timeline-heading">
    <div class="wm360-section__head">
        <h3 id="wm360-timeline-heading" class="wm360-section__title">Attendance Timeline</h3>
    </div>

    <div class="wm360-timeline">
        @foreach($profile->timeline as $day)
            <div
                @class([
                    'wm360-timeline__row',
                    'is-focused' => $day->isFocused,
                    'is-future' => $day->isFuture,
                ])
                data-wm360-day="{{ $day->workDate }}"
                @if($day->isFocused) id="wm360-focused-day" @endif
            >
                <div class="wm360-timeline__day">
                    <span class="wm360-timeline__date">{{ $day->dayLabel }}</span>
                    <span class="attendance-matrix-badge attendance-matrix-badge--{{ $day->tone }}">{{ $day->kindLabel }}</span>
                </div>
                <div class="wm360-timeline__meta">
                    <span>{{ $day->loginLabel ? 'In '.$day->loginLabel : '—' }}</span>
                    <span>{{ $day->logoutLabel ? 'Out '.$day->logoutLabel : '—' }}</span>
                    <span>{{ $day->hoursLabel ?? '—' }}</span>
                    <span>{{ $day->minutesLate !== null ? $day->minutesLate.'m late' : '—' }}</span>
                </div>
            </div>
        @endforeach
    </div>
</section>

<section class="wm360-section" aria-labelledby="wm360-trends-heading">
    <div class="wm360-section__head">
        <h3 id="wm360-trends-heading" class="wm360-section__title">Trends</h3>
    </div>

    @include('workforce-management.member-360.partials.trends', ['trends' => $profile->trends])
</section>

<section class="wm360-section" aria-labelledby="wm360-actions-heading">
    <div class="wm360-section__head">
        <h3 id="wm360-actions-heading" class="wm360-section__title">Quick Actions</h3>
    </div>

    <div class="wm360-actions">
        @foreach($profile->actions as $action)
            @if($action->enabled && $action->url)
                <a
                    href="{{ $action->url }}"
                    class="btn btn-sm btn-outline-secondary wm360-actions__btn"
                    @if(str_starts_with((string) $action->url, '#')) data-wm360-scroll @endif
                >
                    {{ $action->label }}
                </a>
            @else
                <button type="button" class="btn btn-sm btn-outline-secondary wm360-actions__btn" disabled>
                    {{ $action->label }}
                    @if($action->soon)
                        <span class="wm360-soon">Soon</span>
                    @endif
                </button>
            @endif
        @endforeach
    </div>
</section>
