@extends('layouts.app')

@section('title', 'Attendance')

@section('content')
    @php
        /** @var \App\Data\Workforce\AttendanceMatrixReport $report */
        /** @var \App\Data\Workforce\PayrollMonthLockStatus $payrollLock */
    @endphp

    <div class="workforce-management-page" data-workforce-management-attendance>
        <header class="wm-page-header">
            <div class="wm-page-header__eyebrow">Workforce Management</div>
            <h1 class="wm-page-header__title">Attendance</h1>
            <p class="wm-page-header__subtitle">{{ $report->monthLabel }} · team register overview</p>
        </header>

        @include('workforce-management.partials.workspace-nav', ['active' => 'attendance'])

        <section class="wm-payroll-lock" aria-label="Payroll lock status">
            <div class="wm-payroll-lock__status">
                <span class="wm-toolbar__label">Payroll Lock</span>
                @if ($payrollLock->isLocked())
                    <span class="wm-payroll-lock__badge wm-payroll-lock__badge--locked">Locked</span>
                    <div class="wm-payroll-lock__meta">
                        @if ($payrollLock->lockedBy)
                            <span>Locked By {{ $payrollLock->lockedBy }}</span>
                        @endif
                        @if ($payrollLock->lockedOn)
                            <span>Locked On {{ $payrollLock->lockedOn->timezone(config('app.timezone'))->format('M j, Y H:i') }}</span>
                        @endif
                    </div>
                @else
                    <span class="wm-payroll-lock__badge wm-payroll-lock__badge--open">Open</span>
                @endif
            </div>

            @if ($canManagePayrollLock)
                <div class="wm-payroll-lock__actions">
                    @if ($payrollLock->isLocked())
                        <form method="POST" action="{{ route('workforce-management.attendance.payroll-unlock') }}" class="wm-payroll-lock__form">
                            @csrf
                            <input type="hidden" name="month" value="{{ $monthValue }}">
                            <input
                                type="text"
                                name="reason"
                                class="form-control wm-toolbar__control"
                                placeholder="Unlock reason (optional)"
                                maxlength="1000"
                            >
                            <button type="submit" class="btn btn-outline-secondary wm-toolbar__submit">Unlock</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('workforce-management.attendance.payroll-lock') }}" class="wm-payroll-lock__form">
                            @csrf
                            <input type="hidden" name="month" value="{{ $monthValue }}">
                            <input
                                type="text"
                                name="reason"
                                class="form-control wm-toolbar__control"
                                placeholder="Lock reason (optional)"
                                maxlength="1000"
                            >
                            <button type="submit" class="btn btn-primary wm-toolbar__submit">Lock month</button>
                        </form>
                    @endif
                </div>
            @endif
        </section>

        <section class="wm-summary-strip" data-attendance-team-summary aria-label="Team attendance summary">
            <article class="wm-summary-card wm-summary-card--present">
                <div class="wm-summary-card__label">Present</div>
                <div class="wm-summary-card__value" data-summary="present">{{ $report->teamSummary->present }}</div>
            </article>
            <article class="wm-summary-card wm-summary-card--absent">
                <div class="wm-summary-card__label">Absent</div>
                <div class="wm-summary-card__value" data-summary="absent">{{ $report->teamSummary->absent }}</div>
            </article>
            <article class="wm-summary-card wm-summary-card--leave">
                <div class="wm-summary-card__label">Leave</div>
                <div class="wm-summary-card__value" data-summary="leave">{{ $report->teamSummary->leave }}</div>
            </article>
            <article class="wm-summary-card wm-summary-card--late">
                <div class="wm-summary-card__label">Late</div>
                <div class="wm-summary-card__value" data-summary="late">{{ $report->teamSummary->late }}</div>
            </article>
            <article class="wm-summary-card wm-summary-card--holiday">
                <div class="wm-summary-card__label">Holiday</div>
                <div class="wm-summary-card__value" data-summary="holiday">{{ $report->teamSummary->holiday }}</div>
            </article>
        </section>

        <div class="wm-toolbar" role="search">
            <form method="GET" action="{{ route('workforce-management.attendance.index') }}" class="wm-toolbar__form">
                <div class="wm-toolbar__field">
                    <label for="attendance-month" class="wm-toolbar__label">Month</label>
                    <input
                        type="month"
                        id="attendance-month"
                        name="month"
                        class="form-control wm-toolbar__control"
                        value="{{ $monthValue }}"
                        required
                    >
                </div>
                <div class="wm-toolbar__divider" aria-hidden="true"></div>
                <div class="wm-toolbar__field wm-toolbar__field--grow">
                    <label for="attendance-search" class="wm-toolbar__label">Search employee</label>
                    <input
                        type="search"
                        id="attendance-search"
                        class="form-control wm-toolbar__control"
                        placeholder="Filter by name"
                        autocomplete="off"
                        data-attendance-search
                    >
                </div>
                <div class="wm-toolbar__actions">
                    <button type="submit" class="btn btn-primary wm-toolbar__submit">Apply</button>
                </div>
            </form>
        </div>

        <div class="wm-matrix-panel">
            <div class="wm-matrix-panel__meta">
                <div class="attendance-matrix-legend" aria-label="Attendance legend">
                    <span class="attendance-matrix-badge attendance-matrix-badge--success">Present</span>
                    <span class="attendance-matrix-badge attendance-matrix-badge--half">Half Day</span>
                    <span class="attendance-matrix-badge attendance-matrix-badge--warning">Late</span>
                    <span class="attendance-matrix-badge attendance-matrix-badge--danger">Absent</span>
                    <span class="attendance-matrix-badge attendance-matrix-badge--info">Leave</span>
                    <span class="attendance-matrix-badge attendance-matrix-badge--secondary">Holiday</span>
                    <span class="attendance-matrix-badge attendance-matrix-badge--secondary">Off</span>
                    <span class="attendance-matrix-badge attendance-matrix-badge--primary">Extra</span>
                </div>
                <div class="wm-matrix-panel__note">
                    Hours from attendance register · generated {{ $report->generatedAt->format('M j, Y H:i') }}
                    @if ($payrollLock->isLocked())
                        · read-only (payroll locked)
                    @endif
                </div>
            </div>

            <div class="wm-matrix-panel__body">
                @include('workforce-management.partials.attendance-matrix', ['report' => $report])
            </div>
        </div>

        @include('workforce-management.member-360.drawer-host')
    </div>
@endsection
