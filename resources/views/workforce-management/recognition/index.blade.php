@extends('layouts.app')

@section('title', 'Work Recognition')

@section('content')
    <div class="workforce-management-page" data-work-recognition>
        <header class="wm-page-header">
            <div class="wm-page-header__eyebrow">Workforce Management</div>
            <h1 class="wm-page-header__title">Work Recognition</h1>
            <p class="wm-page-header__subtitle">
                Meaningful work on Weekly Off and Company Holidays — independent of Attendance and OT.
            </p>
        </header>

        @include('workforce-management.partials.workspace-nav', ['active' => 'recognition'])

        <section class="wm-summary-strip" aria-label="Recognition summary">
            <article class="wm-summary-card">
                <div class="wm-summary-card__label">Pending</div>
                <div class="wm-summary-card__value">{{ $counts['pending'] }}</div>
            </article>
            <article class="wm-summary-card wm-summary-card--present">
                <div class="wm-summary-card__label">Approved</div>
                <div class="wm-summary-card__value">{{ $counts['approved'] }}</div>
            </article>
            <article class="wm-summary-card wm-summary-card--absent">
                <div class="wm-summary-card__label">No Benefit</div>
                <div class="wm-summary-card__value">{{ $counts['rejected'] }}</div>
            </article>
        </section>

        <div class="wm-toolbar" role="search">
            <form method="GET" action="{{ route('workforce-management.recognition.index') }}" class="wm-toolbar__form">
                <div class="wm-toolbar__field">
                    <label for="recognition-month" class="wm-toolbar__label">Month</label>
                    <input type="month" id="recognition-month" name="month" class="form-control wm-toolbar__control" value="{{ $monthValue }}" required>
                </div>
                <div class="wm-toolbar__field">
                    <label for="recognition-status" class="wm-toolbar__label">Status</label>
                    <select id="recognition-status" name="status" class="form-select wm-toolbar__control">
                        <option value="">All</option>
                        <option value="pending" @selected(($filters['ui_status'] ?? '') === 'pending')>Pending</option>
                        <option value="approved" @selected(($filters['ui_status'] ?? '') === 'approved')>Approved</option>
                        <option value="rejected" @selected(($filters['ui_status'] ?? '') === 'rejected')>No Benefit</option>
                    </select>
                </div>
                <div class="wm-toolbar__field">
                    <label for="recognition-context" class="wm-toolbar__label">Day type</label>
                    <select id="recognition-context" name="day_context" class="form-select wm-toolbar__control">
                        <option value="">All</option>
                        @foreach ($dayContexts as $context)
                            <option value="{{ $context->value }}" @selected(($filters['day_context'] ?? '') === $context->value)>{{ $context->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="wm-toolbar__field">
                    <label for="recognition-pack" class="wm-toolbar__label">Department</label>
                    <select id="recognition-pack" name="department_pack" class="form-select wm-toolbar__control">
                        <option value="">All</option>
                        @foreach ($departmentPacks as $packId => $pack)
                            <option value="{{ $packId }}" @selected(($filters['department_pack'] ?? '') === $packId)>{{ $pack['label'] ?? $packId }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="wm-toolbar__field wm-toolbar__field--grow">
                    <label for="recognition-user" class="wm-toolbar__label">Employee</label>
                    <select id="recognition-user" name="user_id" class="form-select wm-toolbar__control">
                        <option value="">All</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}" @selected((string) ($filters['user_id'] ?? '') === (string) $employee->id)>{{ $employee->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="wm-toolbar__actions">
                    <button type="submit" class="btn btn-primary wm-toolbar__submit">Apply</button>
                </div>
            </form>

            @if ($canScan)
                <form method="POST" action="{{ route('workforce-management.recognition.scan') }}" class="mt-2">
                    @csrf
                    <input type="hidden" name="month" value="{{ $monthValue }}">
                    <button type="submit" class="btn btn-outline-secondary btn-sm">Scan month for candidates</button>
                </form>
            @endif
        </div>

        <div class="wm-matrix-panel">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Date</th>
                            <th>Context</th>
                            <th>Login</th>
                            <th>Productive</th>
                            <th>Evidence</th>
                            <th>IRA Score</th>
                            <th>IRA Rec</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($reviews as $review)
                            @php
                                $summary = $review->evidence_snapshot['evidence_summary'] ?? [];
                            @endphp
                            <tr>
                                <td>{{ $review->user?->name }}</td>
                                <td>{{ $review->work_date->toDateString() }}</td>
                                <td>{{ $review->day_context->label() }}</td>
                                <td>{{ number_format(($review->login_seconds ?? 0) / 3600, 1) }}h</td>
                                <td>{{ number_format(($review->productive_seconds ?? 0) / 3600, 1) }}h</td>
                                <td class="small text-muted">{{ is_array($summary) ? \Illuminate\Support\Str::limit(implode('; ', $summary), 80) : '—' }}</td>
                                <td>{{ $review->ira_score }}</td>
                                <td>{{ $review->ira_recommendation->label() }}</td>
                                <td>
                                    @if ($review->isPending())
                                        Pending
                                    @elseif ($review->isApprovedBenefit())
                                        Approved · {{ $review->decision?->label() }}
                                    @else
                                        No Benefit
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('workforce-management.recognition.show', $review) }}" class="btn btn-sm btn-outline-primary">Open</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-muted py-4 text-center">No recognition reviews for this filter.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $reviews->links() }}</div>
        </div>
    </div>
@endsection
