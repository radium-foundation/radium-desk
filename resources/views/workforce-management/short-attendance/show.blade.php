@extends('layouts.app')

@section('title', 'Short Attendance Case')

@section('content')
    @php
        /** @var \App\Models\WorkforceShortAttendanceReview $review */
        $filterQuery = array_filter([
            'period' => $filters['period'] ?? 'today',
            'status' => $filters['ui_status'] ?? 'pending',
            'user_id' => ($filters['user_id'] ?? '') !== '' ? $filters['user_id'] : null,
            'month' => ($filters['period'] ?? '') === 'this_month' ? ($filters['month'] ?? null) : null,
        ], fn ($value) => $value !== null && $value !== '');
    @endphp

    <div
        class="workforce-management-page"
        data-short-attendance-review-show
        data-prev-url="{{ $previousReview ? route('workforce-management.short-attendance.show', ['review' => $previousReview, ...$filterQuery]) : '' }}"
        data-next-url="{{ $nextReview ? route('workforce-management.short-attendance.show', ['review' => $nextReview, ...$filterQuery]) : '' }}"
    >
        <header class="wm-page-header">
            <div class="wm-page-header__eyebrow">
                <a
                    href="{{ route('workforce-management.short-attendance.index', $filterQuery) }}"
                    class="text-decoration-none"
                >
                    ← Short Attendance Review
                </a>
            </div>
            <h1 class="wm-page-header__title">{{ $review->user?->name ?? 'Employee' }}</h1>
            <p class="wm-page-header__subtitle">
                {{ $review->work_date->format('l, M j Y') }} · Phase 1 calculated Short Attendance
            </p>
        </header>

        @include('workforce-management.partials.workspace-nav', ['active' => 'short-attendance'])

        @if (session('status') === 'short-attendance-reviewed-next')
            <div class="alert alert-success">Decision saved. Continuing to next pending case.</div>
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

        <div class="d-flex flex-wrap gap-2 mb-3" role="navigation" aria-label="Case navigation">
            @if ($previousReview)
                <a
                    href="{{ route('workforce-management.short-attendance.show', ['review' => $previousReview, ...$filterQuery]) }}"
                    class="btn btn-outline-secondary btn-sm"
                    data-sa-nav="prev"
                >← Previous</a>
            @else
                <button type="button" class="btn btn-outline-secondary btn-sm" disabled>← Previous</button>
            @endif
            @if ($nextReview)
                <a
                    href="{{ route('workforce-management.short-attendance.show', ['review' => $nextReview, ...$filterQuery]) }}"
                    class="btn btn-outline-secondary btn-sm"
                    data-sa-nav="next"
                >Next →</a>
            @else
                <button type="button" class="btn btn-outline-secondary btn-sm" disabled>Next →</button>
            @endif
            <span class="text-muted small align-self-center ms-1">Keyboard: ← / → or J / K</span>
        </div>

        <div class="row g-3">
            <div class="col-lg-7">
                <section class="card shadow-sm border-0">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Evidence</h2>
                        <dl class="row mb-0 small">
                            <dt class="col-sm-4">Worked Minutes</dt>
                            <dd class="col-sm-8 fw-semibold">{{ $review->worked_minutes }} min</dd>

                            <dt class="col-sm-4">First Login</dt>
                            <dd class="col-sm-8">{{ $review->first_login_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '—' }}</dd>

                            <dt class="col-sm-4">Last Activity</dt>
                            <dd class="col-sm-8">{{ $review->last_activity_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '—' }}</dd>

                            <dt class="col-sm-4">Last Logout</dt>
                            <dd class="col-sm-8">{{ $review->last_logout_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '—' }}</dd>

                            <dt class="col-sm-4">Auto Logout</dt>
                            <dd class="col-sm-8">
                                {{ $review->had_auto_logout ? 'Yes' : 'No' }}
                                @if ($review->away_timeout_count > 0)
                                    ({{ $review->away_timeout_count }})
                                @endif
                            </dd>

                            <dt class="col-sm-4">Sessions</dt>
                            <dd class="col-sm-8">{{ $review->session_count }}</dd>

                            <dt class="col-sm-4">Shift</dt>
                            <dd class="col-sm-8">{{ $review->shift_label ?? '—' }}</dd>

                            <dt class="col-sm-4">Department</dt>
                            <dd class="col-sm-8">{{ $review->department ?? '—' }}</dd>

                            <dt class="col-sm-4">Manager</dt>
                            <dd class="col-sm-8">{{ $review->manager_name ?? '—' }}</dd>

                            <dt class="col-sm-4">Reason</dt>
                            <dd class="col-sm-8"><code>{{ $review->calculated_reason ?? 'short_attendance' }}</code></dd>

                            <dt class="col-sm-4">Register Status</dt>
                            <dd class="col-sm-8"><span class="badge attendance-matrix-badge attendance-matrix-badge--short">SA</span> Short Attendance</dd>
                        </dl>
                        {{-- Future: manager comments, employee explanation, WFH / field work, evidence upload --}}
                    </div>
                </section>
            </div>

            <div class="col-lg-5">
                <section class="card shadow-sm border-0">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">HR Decision</h2>

                        @if ($review->isDecided())
                            <dl class="row mb-0 small">
                                <dt class="col-sm-5">Decision</dt>
                                <dd class="col-sm-7 fw-semibold">{{ $review->decision?->label() }}</dd>

                                <dt class="col-sm-5">Previous Status</dt>
                                <dd class="col-sm-7"><code>{{ $review->previous_status }}</code></dd>

                                <dt class="col-sm-5">New Status</dt>
                                <dd class="col-sm-7"><code>{{ $review->new_status }}</code></dd>

                                <dt class="col-sm-5">Reason</dt>
                                <dd class="col-sm-7">{{ $review->decision_reason ?? '—' }}</dd>

                                <dt class="col-sm-5">Note</dt>
                                <dd class="col-sm-7">{{ $review->decision_note ?? '—' }}</dd>

                                <dt class="col-sm-5">HR User</dt>
                                <dd class="col-sm-7">{{ $review->decider?->name ?? '—' }}</dd>

                                <dt class="col-sm-5">Timestamp</dt>
                                <dd class="col-sm-7">{{ $review->decided_at?->timezone(config('app.timezone'))->format('M j, Y H:i') ?? '—' }}</dd>
                            </dl>
                        @elseif ($canDecide)
                            <form method="POST" action="{{ route('workforce-management.short-attendance.decide', $review) }}">
                                @csrf
                                <input type="hidden" name="period" value="{{ $filters['period'] ?? 'today' }}">
                                <input type="hidden" name="status" value="{{ $filters['ui_status'] ?? 'pending' }}">
                                @if (($filters['user_id'] ?? '') !== '')
                                    <input type="hidden" name="user_id" value="{{ $filters['user_id'] }}">
                                @endif
                                @if (($filters['period'] ?? '') === 'this_month')
                                    <input type="hidden" name="month" value="{{ $filters['month'] }}">
                                @endif

                                <div class="mb-3">
                                    <label class="form-label" for="decision">Action</label>
                                    <select name="decision" id="decision" class="form-select @error('decision') is-invalid @enderror" required>
                                        <option value="">Select…</option>
                                        @foreach ($decisions as $decision)
                                            <option value="{{ $decision->value }}" @selected(old('decision') === $decision->value)>
                                                {{ $decision->label() }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('decision')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="decision_reason">Reason <span class="text-danger">*</span></label>
                                    <textarea
                                        name="decision_reason"
                                        id="decision_reason"
                                        class="form-control @error('decision_reason') is-invalid @enderror"
                                        rows="3"
                                        maxlength="1000"
                                        required
                                    >{{ old('decision_reason') }}</textarea>
                                    @error('decision_reason')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="decision_note">Optional Note</label>
                                    <textarea
                                        name="decision_note"
                                        id="decision_note"
                                        class="form-control @error('decision_note') is-invalid @enderror"
                                        rows="2"
                                        maxlength="2000"
                                    >{{ old('decision_note') }}</textarea>
                                    @error('decision_note')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <button type="submit" class="btn btn-primary">Save Decision</button>
                            </form>
                        @else
                            <p class="text-muted small mb-0">You can view this case but are not authorized to decide.</p>
                        @endif
                    </div>
                </section>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            (function () {
                const root = document.querySelector('[data-short-attendance-review-show]');
                if (!root) return;

                const prevUrl = root.getAttribute('data-prev-url') || '';
                const nextUrl = root.getAttribute('data-next-url') || '';

                document.addEventListener('keydown', function (event) {
                    const tag = (event.target && event.target.tagName) ? event.target.tagName.toLowerCase() : '';
                    if (tag === 'input' || tag === 'textarea' || tag === 'select' || event.target.isContentEditable) {
                        return;
                    }

                    if ((event.key === 'ArrowLeft' || event.key === 'k' || event.key === 'K') && prevUrl) {
                        event.preventDefault();
                        window.location.href = prevUrl;
                    }

                    if ((event.key === 'ArrowRight' || event.key === 'j' || event.key === 'J') && nextUrl) {
                        event.preventDefault();
                        window.location.href = nextUrl;
                    }
                });
            })();
        </script>
    @endpush
@endsection
