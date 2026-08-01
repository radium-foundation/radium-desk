@extends('layouts.app')

@section('title', 'Payroll')

@section('content')
    @php
        /** @var \Illuminate\Support\Collection<int, \App\Data\Workforce\Payroll\PayrollMonthResult> $rows */
        /** @var \App\Data\Workforce\PayrollMonthLockStatus $payrollLock */
        /** @var \App\Models\PayrollMonthRun|null $payrollRun */
    @endphp

    <div class="workforce-management-page" data-workforce-payroll>
        <header class="wm-page-header">
            <div class="wm-page-header__eyebrow">Workforce Management</div>
            <h1 class="wm-page-header__title">Payroll</h1>
            <p class="wm-page-header__subtitle">{{ $monthLabel }} · Phase 1 day-rate calculation</p>
        </header>

        @include('workforce-management.partials.workspace-nav', ['active' => 'payroll'])

        <section class="wm-payroll-lock" aria-label="Payroll status">
            <div class="wm-payroll-lock__status">
                <span class="wm-toolbar__label">Payroll Status</span>
                @if ($isFinalized)
                    <span class="wm-payroll-lock__badge wm-payroll-lock__badge--finalized">Finalized Payroll</span>
                    <div class="wm-payroll-lock__meta">
                        @if ($payrollRun?->finalizer)
                            <span>Finalized By {{ $payrollRun->finalizer->name }}</span>
                        @endif
                        @if ($payrollRun?->finalized_at)
                            <span>Finalized On {{ $payrollRun->finalized_at->timezone(config('app.timezone'))->format('M j, Y H:i') }}</span>
                        @endif
                        <span>Snapshot · immutable</span>
                    </div>
                @else
                    <span class="wm-payroll-lock__badge wm-payroll-lock__badge--draft">Draft Payroll</span>
                    <div class="wm-payroll-lock__meta">
                        <span>Live calculation · not frozen</span>
                    </div>
                @endif
            </div>
            <div class="wm-payroll-lock__actions">
                <a href="{{ route('workforce-management.salaries.index') }}" class="btn btn-outline-secondary btn-sm">Manage salaries</a>
                @if ($canFinalizePayroll && ! $isFinalized)
                    @if ($payrollLock->isLocked())
                        <form method="POST" action="{{ route('workforce-management.payroll.finalize') }}" class="wm-payroll-lock__form" onsubmit="return confirm('Finalize payroll for {{ $monthLabel }}? Values will be frozen and cannot be changed by later salary or attendance edits.');">
                            @csrf
                            <input type="hidden" name="month" value="{{ $monthValue }}">
                            <div class="wm-toolbar__field">
                                <label for="payroll-finalize-notes" class="wm-toolbar__label">Notes (optional)</label>
                                <input type="text" id="payroll-finalize-notes" name="notes" class="form-control wm-toolbar__control" maxlength="1000" placeholder="Finalize notes">
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">Finalize Payroll</button>
                        </form>
                    @else
                        <p class="text-muted small mb-0">Lock attendance for this month before finalizing payroll.</p>
                    @endif
                @endif
            </div>
        </section>

        <section class="wm-payroll-lock" aria-label="Attendance lock status">
            <div class="wm-payroll-lock__status">
                <span class="wm-toolbar__label">Attendance Lock</span>
                @if ($payrollLock->isLocked())
                    <span class="wm-payroll-lock__badge wm-payroll-lock__badge--locked">Locked</span>
                    <div class="wm-payroll-lock__meta">
                        @if ($payrollLock->lockedBy)
                            <span>Locked By {{ $payrollLock->lockedBy }}</span>
                        @endif
                        @if ($payrollLock->lockedOn)
                            <span>Locked On {{ $payrollLock->lockedOn->timezone(config('app.timezone'))->format('M j, Y H:i') }}</span>
                        @endif
                        <span>Separate from finalize</span>
                    </div>
                @else
                    <span class="wm-payroll-lock__badge wm-payroll-lock__badge--open">Open</span>
                    <div class="wm-payroll-lock__meta">
                        <span>Required before finalize</span>
                    </div>
                @endif
            </div>
        </section>

        <div class="wm-toolbar" role="search">
            <form method="GET" action="{{ route('workforce-management.payroll.index') }}" class="wm-toolbar__form">
                <div class="wm-toolbar__field">
                    <label for="payroll-month" class="wm-toolbar__label">Month</label>
                    <input type="month" id="payroll-month" name="month" class="form-control wm-toolbar__control" value="{{ $monthValue }}" required>
                </div>
                <div class="wm-toolbar__actions">
                    <button type="submit" class="btn btn-primary wm-toolbar__submit">Apply</button>
                </div>
            </form>
        </div>

        <div class="wm-matrix-panel">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th class="text-end">Monthly Salary</th>
                            <th class="text-end">Payable Days</th>
                            <th class="text-end">Non-payable Days</th>
                            <th class="text-end">Net Salary</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td>
                                    <a href="{{ route('workforce-management.payroll.show', ['user' => $row->userId, 'month' => $monthValue]) }}">
                                        {{ $row->employeeName }}
                                    </a>
                                </td>
                                <td class="text-end">₹{{ number_format($row->monthlySalary, 2) }}</td>
                                <td class="text-end">{{ number_format($row->payableDays, 1) }}</td>
                                <td class="text-end">{{ number_format($row->nonPayableDays, 1) }}</td>
                                <td class="text-end fw-semibold">₹{{ number_format($row->netSalary, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-muted text-center py-4">
                                    No payroll rows. Add active salaries for attendance-tracked employees.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
