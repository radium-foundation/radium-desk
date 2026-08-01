@extends('layouts.app')

@section('title', 'Payroll · '.$result->employeeName)

@section('content')
    @php
        /** @var \App\Data\Workforce\Payroll\PayrollMonthResult $result */
        /** @var \App\Data\Workforce\PayrollMonthLockStatus $payrollLock */
        /** @var \App\Models\PayrollMonthRun|null $payrollRun */
        $effectiveFrom = $result->salaryRecord?->effective_from?->toDateString()
            ?? ($result->isSnapshot ? ($payrollRun?->lines->firstWhere('user_id', $result->userId)?->attendance_summary_json['salary_effective_from'] ?? null) : null);
    @endphp

    <div class="workforce-management-page" data-workforce-payroll-detail>
        <header class="wm-page-header">
            <div class="wm-page-header__eyebrow">Workforce Management · Payroll</div>
            <h1 class="wm-page-header__title">{{ $result->employeeName }}</h1>
            <p class="wm-page-header__subtitle">{{ $monthLabel }} · {{ $isFinalized ? 'finalized snapshot' : 'live salary calculation' }}</p>
        </header>

        @include('workforce-management.partials.workspace-nav', ['active' => 'payroll'])

        <div class="mb-3">
            <a href="{{ route('workforce-management.payroll.index', ['month' => $monthValue]) }}" class="btn btn-outline-secondary btn-sm">← Back to payroll</a>
        </div>

        <section class="wm-payroll-lock mb-3" aria-label="Payroll status">
            <div class="wm-payroll-lock__status">
                <span class="wm-toolbar__label">Payroll Status</span>
                @if ($isFinalized)
                    <span class="wm-payroll-lock__badge wm-payroll-lock__badge--finalized">Finalized Payroll</span>
                @else
                    <span class="wm-payroll-lock__badge wm-payroll-lock__badge--draft">Draft Payroll</span>
                @endif
            </div>
            <div class="wm-payroll-lock__status">
                <span class="wm-toolbar__label">Attendance Lock</span>
                @if ($payrollLock->isLocked())
                    <span class="wm-payroll-lock__badge wm-payroll-lock__badge--locked">Locked</span>
                @else
                    <span class="wm-payroll-lock__badge wm-payroll-lock__badge--open">Open</span>
                @endif
            </div>
        </section>

        <div class="wm-toolbar" role="search">
            <form method="GET" action="{{ route('workforce-management.payroll.show', $user) }}" class="wm-toolbar__form">
                <div class="wm-toolbar__field">
                    <label for="payroll-detail-month" class="wm-toolbar__label">Month</label>
                    <input type="month" id="payroll-detail-month" name="month" class="form-control wm-toolbar__control" value="{{ $monthValue }}" required>
                </div>
                <div class="wm-toolbar__actions">
                    <button type="submit" class="btn btn-primary wm-toolbar__submit">Apply</button>
                </div>
            </form>
        </div>

        <section class="wm-summary-strip" aria-label="Attendance summary">
            <article class="wm-summary-card wm-summary-card--present">
                <div class="wm-summary-card__label">Present</div>
                <div class="wm-summary-card__value">{{ $result->presentDays }}</div>
            </article>
            <article class="wm-summary-card">
                <div class="wm-summary-card__label">Late</div>
                <div class="wm-summary-card__value">{{ $result->lateDays }}</div>
            </article>
            <article class="wm-summary-card wm-summary-card--leave">
                <div class="wm-summary-card__label">Leave</div>
                <div class="wm-summary-card__value">{{ $result->leaveDays }}</div>
            </article>
            <article class="wm-summary-card">
                <div class="wm-summary-card__label">Half Day</div>
                <div class="wm-summary-card__value">{{ $result->halfDayDays }}</div>
            </article>
            <article class="wm-summary-card">
                <div class="wm-summary-card__label">Weekly Off</div>
                <div class="wm-summary-card__value">{{ $result->weeklyOffDays }}</div>
            </article>
            <article class="wm-summary-card">
                <div class="wm-summary-card__label">Holiday</div>
                <div class="wm-summary-card__value">{{ $result->holidayDays }}</div>
            </article>
            <article class="wm-summary-card wm-summary-card--absent">
                <div class="wm-summary-card__label">Absent</div>
                <div class="wm-summary-card__value">{{ $result->absentDays }}</div>
            </article>
            <article class="wm-summary-card">
                <div class="wm-summary-card__label">Extra (ignored)</div>
                <div class="wm-summary-card__value">{{ $result->extraDays }}</div>
            </article>
        </section>

        <div class="wm-matrix-panel">
            <div class="p-3">
                <h2 class="h6 mb-3">Salary calculation</h2>
                <dl class="row mb-0">
                    <dt class="col-sm-4">Monthly Salary</dt>
                    <dd class="col-sm-8">₹{{ number_format($result->monthlySalary, 2) }}</dd>

                    <dt class="col-sm-4">Salary Effective From</dt>
                    <dd class="col-sm-8">{{ $effectiveFrom ?? '—' }}</dd>

                    <dt class="col-sm-4">Calendar Days</dt>
                    <dd class="col-sm-8">{{ $result->calendarDays }}</dd>

                    <dt class="col-sm-4">Day Rate</dt>
                    <dd class="col-sm-8">₹{{ number_format($result->dayRate, 4) }}</dd>

                    <dt class="col-sm-4">Payable Days</dt>
                    <dd class="col-sm-8">{{ number_format($result->payableDays, 1) }}</dd>

                    <dt class="col-sm-4">Non-payable Days</dt>
                    <dd class="col-sm-8">{{ number_format($result->nonPayableDays, 1) }}</dd>

                    <dt class="col-sm-4">Gross Salary</dt>
                    <dd class="col-sm-8">₹{{ number_format($result->grossSalary, 2) }}</dd>

                    <dt class="col-sm-4">Net Salary</dt>
                    <dd class="col-sm-8 fw-semibold">₹{{ number_format($result->netSalary, 2) }}</dd>
                </dl>

                <p class="text-muted small mt-3 mb-0">
                    @if ($isFinalized)
                        Frozen snapshot from finalize. Later salary revisions and attendance edits do not change this month.
                    @else
                        Formula: Net = (Monthly Salary ÷ {{ $result->calendarDays }}) × {{ number_format($result->payableDays, 1) }} payable days.
                        Payable = Present, Late, Weekly Off, Holiday, Paid Leave, Half Day (0.5). Extra ignored in Phase 1.
                    @endif
                </p>
            </div>
        </div>
    </div>
@endsection
