@extends('layouts.app')

@section('title', 'Short Attendance Review')

@section('content')
    <div class="workforce-management-page" data-short-attendance-review>
        <header class="wm-page-header">
            <div class="wm-page-header__eyebrow">Workforce Management · Attendance</div>
            <h1 class="wm-page-header__title">Short Attendance Review</h1>
            <p class="wm-page-header__subtitle">
                Daily HR review for Short Attendance — complete today’s queue before leaving.
            </p>
        </header>

        @include('workforce-management.partials.workspace-nav', ['active' => 'short-attendance'])

        @if (session('status') === 'short-attendance-reviewed')
            <div class="alert alert-success">Short Attendance decision saved. Queue clear for this filter.</div>
        @endif

        @if ($showMorningReminder)
            <div class="alert alert-warning" role="status">
                <strong>Morning reminder:</strong>
                Yesterday still has pending Short Attendance reviews.
                <a
                    href="{{ route('workforce-management.short-attendance.index', ['period' => 'yesterday', 'status' => 'pending']) }}"
                    class="alert-link"
                >Open yesterday’s queue</a>
            </div>
        @endif

        <section class="wm-summary-strip" aria-label="Short Attendance review summary">
            <article class="wm-summary-card wm-summary-card--absent">
                <div class="wm-summary-card__label">Pending Today</div>
                <div class="wm-summary-card__value">{{ $counts['pending_today'] }}</div>
            </article>
            <article class="wm-summary-card">
                <div class="wm-summary-card__label">Pending Yesterday</div>
                <div class="wm-summary-card__value">{{ $counts['pending_yesterday'] }}</div>
            </article>
            <article class="wm-summary-card">
                <div class="wm-summary-card__label">Total Pending</div>
                <div class="wm-summary-card__value">{{ $counts['pending_total'] }}</div>
            </article>
            <article class="wm-summary-card">
                <div class="wm-summary-card__label">In view</div>
                <div class="wm-summary-card__value">{{ $counts['pending'] }} / {{ $counts['total'] }}</div>
                <div class="wm-summary-card__meta">Pending · total for filter</div>
            </article>
        </section>

        <div class="wm-toolbar" role="search">
            <form method="GET" action="{{ route('workforce-management.short-attendance.index') }}" class="wm-toolbar__form">
                <div class="wm-toolbar__field">
                    <label for="sa-period" class="wm-toolbar__label">Period</label>
                    <select id="sa-period" name="period" class="form-select wm-toolbar__control">
                        @foreach ($periods as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['period'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @if (($filters['period'] ?? '') === 'this_month')
                    <div class="wm-toolbar__field">
                        <label for="sa-month" class="wm-toolbar__label">Month</label>
                        <input type="month" id="sa-month" name="month" class="form-control wm-toolbar__control" value="{{ $filters['month'] }}">
                    </div>
                @endif
                <div class="wm-toolbar__field">
                    <label for="sa-status" class="wm-toolbar__label">Status</label>
                    <select id="sa-status" name="status" class="form-select wm-toolbar__control">
                        <option value="pending" @selected(($filters['ui_status'] ?? '') === 'pending')>Pending</option>
                        <option value="decided" @selected(($filters['ui_status'] ?? '') === 'decided')>Decided</option>
                        <option value="" @selected(($filters['ui_status'] ?? '') === '')>All</option>
                    </select>
                </div>
                <div class="wm-toolbar__field wm-toolbar__field--grow">
                    <label for="sa-user" class="wm-toolbar__label">Employee</label>
                    <select id="sa-user" name="user_id" class="form-select wm-toolbar__control">
                        <option value="">All</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}" @selected((string) ($filters['user_id'] ?? '') === (string) $employee->id)>
                                {{ $employee->name }}
                            </option>
                        @endforeach
                    </select>
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
                            <th>Date</th>
                            <th>Worked</th>
                            <th>First Login</th>
                            <th>Last Activity</th>
                            <th>Auto Logout</th>
                            <th>Sessions</th>
                            <th>Shift</th>
                            <th>Department</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($reviews as $review)
                            <tr>
                                <td class="fw-semibold">{{ $review->user?->name ?? '—' }}</td>
                                <td>{{ $review->work_date->format('D, M j') }}</td>
                                <td>{{ $review->worked_minutes }} min</td>
                                <td>{{ $review->first_login_at?->timezone(config('app.timezone'))->format('H:i') ?? '—' }}</td>
                                <td>{{ $review->last_activity_at?->timezone(config('app.timezone'))->format('H:i') ?? '—' }}</td>
                                <td>
                                    @if ($review->had_auto_logout)
                                        <span class="badge text-bg-warning">Yes</span>
                                    @else
                                        No
                                    @endif
                                </td>
                                <td>{{ $review->session_count }}</td>
                                <td>{{ $review->shift_label ?? '—' }}</td>
                                <td>{{ $review->department ?? '—' }}</td>
                                <td><code class="small">{{ $review->calculated_reason ?? 'short_attendance' }}</code></td>
                                <td>
                                    @if ($review->isPending())
                                        <span class="badge text-bg-warning">Pending</span>
                                    @else
                                        <span class="badge text-bg-secondary">{{ $review->decision?->label() ?? 'Decided' }}</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a
                                        href="{{ route('workforce-management.short-attendance.show', [
                                            'review' => $review,
                                            'period' => $filters['period'],
                                            'status' => $filters['ui_status'],
                                            'user_id' => $filters['user_id'] ?: null,
                                            'month' => ($filters['period'] ?? '') === 'this_month' ? $filters['month'] : null,
                                        ]) }}"
                                        class="btn btn-sm btn-outline-primary"
                                    >
                                        {{ $review->isPending() && $canDecide ? 'Review' : 'View' }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12" class="text-muted text-center py-4">
                                    No Short Attendance cases for this filter.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="p-3">
                {{ $reviews->links() }}
            </div>
        </div>
    </div>
@endsection
