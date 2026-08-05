@extends('layouts.app')

@section('title', 'Performance Intelligence — Explain')

@section('content')
    @php
        $inputs = $snapshot->inputs ?? [];
        $breakdown = $snapshot->breakdown ?? [];
        $explanations = $snapshot->explanations ?? [];
        $flags = $snapshot->feature_flags ?? [];
    @endphp

    <div class="mb-4">
        <h1 class="h3 mb-1">{{ $snapshot->user?->name ?? ('User #'.$snapshot->user_id) }}</h1>
        <p class="text-muted mb-0">
            Shadow explanation for {{ $date->toDateString() }} ·
            calculated {{ $snapshot->calculated_at?->timezone(config('app.timezone'))?->format('Y-m-d H:i') }} ·
            {{ (int) $snapshot->calculation_duration_ms }} ms ·
            version <code>{{ $snapshot->version }}</code>
        </p>
    </div>

    @include('navigation.administration-workspace-nav', ['active' => 'performance_intelligence'])

    <p class="mb-3">
        <a href="{{ route('admin.performance-intelligence.index', ['date' => $date->toDateString()]) }}" class="btn btn-sm btn-outline-secondary">
            ← Back to date
        </a>
    </p>

    <div class="row g-3 mb-4">
        <div class="col-md-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Composite</div>
                    <div class="h3 mb-0">{{ number_format((float) $snapshot->composite_score, 1) }}</div>
                </div>
            </div>
        </div>
        @foreach([
            'Outcome' => $snapshot->outcome_score,
            'Reach' => $snapshot->reach_score,
            'Contribution' => $snapshot->contribution_score,
            'Commitment' => $snapshot->commitment_score,
            'Quality' => $snapshot->quality_score,
        ] as $label => $value)
            <div class="col">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">{{ $label }}</div>
                        <div class="h4 mb-0">{{ $value }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <strong>Raw inputs</strong>
                </div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-7">Cases Worked</dt><dd class="col-5">{{ (int) ($inputs['cases_worked'] ?? 0) }}</dd>
                        <dt class="col-7">Customer Touches (diagnostic)</dt><dd class="col-5">{{ (int) ($inputs['customer_touches'] ?? 0) }}</dd>
                        <dt class="col-7">Resolved</dt><dd class="col-5">{{ (int) ($inputs['resolved_count'] ?? 0) }}</dd>
                        <dt class="col-7">Closed</dt><dd class="col-5">{{ (int) ($inputs['closed_count'] ?? 0) }}</dd>
                        <dt class="col-7">Reopened</dt><dd class="col-5">{{ (int) ($inputs['reopen_count'] ?? 0) }}</dd>
                        <dt class="col-7">Refund decisions</dt><dd class="col-5">{{ (int) ($inputs['refund_decision_count'] ?? 0) }}</dd>
                        <dt class="col-7">Answered calls</dt><dd class="col-5">{{ (int) ($inputs['answered_call_count'] ?? 0) }}</dd>
                        <dt class="col-7">Manual WhatsApp</dt><dd class="col-5">{{ (int) (($inputs['touch_breakdown']['whatsapp'] ?? 0)) }}</dd>
                        <dt class="col-7">Emails</dt><dd class="col-5">{{ (int) (($inputs['touch_breakdown']['emails'] ?? 0)) }}</dd>
                        <dt class="col-7">Manual remarks</dt><dd class="col-5">{{ (int) (($inputs['touch_breakdown']['remarks'] ?? 0)) }}</dd>
                        <dt class="col-7">Status updates</dt><dd class="col-5">{{ (int) (($inputs['touch_breakdown']['status_updates'] ?? 0)) }}</dd>
                        <dt class="col-7">Assign / escalate</dt><dd class="col-5">{{ (int) ($inputs['assign_or_escalate_count'] ?? 0) }}</dd>
                        <dt class="col-7">Attendance status</dt><dd class="col-5">{{ $inputs['attendance_status'] ?? '—' }}</dd>
                        <dt class="col-7">Extra / Leave / Holiday</dt>
                        <dd class="col-5">
                            {{ ! empty($inputs['attendance_extra']) ? 'Extra ' : '' }}
                            {{ ! empty($inputs['attendance_on_leave']) ? 'Leave ' : '' }}
                            {{ ! empty($inputs['is_company_holiday']) ? 'Holiday' : '' }}
                            @if(empty($inputs['attendance_extra']) && empty($inputs['attendance_on_leave']) && empty($inputs['is_company_holiday']))
                                —
                            @endif
                        </dd>
                        <dt class="col-7">Overtime seconds (payroll)</dt><dd class="col-5">{{ (int) ($inputs['overtime_seconds'] ?? 0) }}</dd>
                    </dl>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <strong>Owner intuition vs score</strong>
                </div>
                <div class="card-body">
                    <p class="small text-muted">
                        Phase 0 architecture placeholder — capture your gut rating to compare later.
                        No AI. Values stay in the query string only (not persisted yet).
                    </p>
                    <form method="get" class="row g-2 align-items-end">
                        <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                        <div class="col-md-6">
                            <label class="form-label" for="intuition">My intuition (0–100)</label>
                            <input type="number" min="0" max="100" step="1" name="intuition" id="intuition"
                                   class="form-control" value="{{ $intuitionNote }}">
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-outline-primary">Compare</button>
                        </div>
                    </form>
                    @if($intuitionNote !== '')
                        <div class="mt-3 small">
                            Intuition: <strong>{{ $intuitionNote }}</strong>
                            · Composite: <strong>{{ number_format((float) $snapshot->composite_score, 1) }}</strong>
                            · Delta: <strong>{{ number_format((float) $intuitionNote - (float) $snapshot->composite_score, 1) }}</strong>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <strong>Why this score</strong>
                </div>
                <div class="card-body">
                    @foreach(['outcome' => 'Outcome', 'reach' => 'Reach', 'contribution' => 'Contribution', 'commitment' => 'Commitment', 'quality' => 'Quality', 'composite' => 'Composite'] as $key => $label)
                        <h2 class="h6 mt-3">{{ $label }}</h2>
                        <ul class="small mb-0">
                            @foreach($explanations[$key] ?? [] as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                            @if(($explanations[$key] ?? []) === [])
                                <li class="text-muted">No lines</li>
                            @endif
                        </ul>
                    @endforeach
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <strong>Breakdown / flags</strong>
                </div>
                <div class="card-body">
                    <pre class="small mb-0" style="white-space: pre-wrap">{{ json_encode(['breakdown' => $breakdown, 'feature_flags' => $flags], JSON_PRETTY_PRINT) }}</pre>
                </div>
            </div>
        </div>
    </div>
@endsection
