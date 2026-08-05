@extends('layouts.app')

@section('title', 'Performance Intelligence')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Performance Intelligence</h1>
        <p class="text-muted mb-0">
            Phase 0 shadow mode — Super Admin only. No employee visibility, rankings, badges, or rewards.
            Version <code>{{ $version }}</code>
        </p>
    </div>

    @include('navigation.administration-workspace-nav', ['active' => 'performance_intelligence'])

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="get" action="{{ route('admin.performance-intelligence.index') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label for="pi-date" class="form-label">Snapshot date</label>
                    <input type="date" name="date" id="pi-date" class="form-control"
                           value="{{ $date->toDateString() }}">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-outline-primary">Load</button>
                </div>
            </form>

            <form method="post" action="{{ route('admin.performance-intelligence.capture') }}" class="mt-3">
                @csrf
                <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                <button type="submit" class="btn btn-sm btn-secondary">
                    Recalculate snapshots for {{ $date->toDateString() }}
                </button>
            </form>

            @if($availableDates !== [])
                <p class="small text-muted mt-3 mb-0">
                    Available dates:
                    @foreach(array_slice($availableDates, 0, 10) as $available)
                        <a href="{{ route('admin.performance-intelligence.index', ['date' => $available]) }}">{{ $available }}</a>@if(! $loop->last), @endif
                    @endforeach
                </p>
            @endif
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <div class="small text-muted">
                Composite weights —
                Outcome {{ number_format(($weights['outcome'] ?? 0) * 100, 0) }}% ·
                Reach {{ number_format(($weights['reach'] ?? 0) * 100, 0) }}% ·
                Contribution {{ number_format(($weights['contribution'] ?? 0) * 100, 0) }}% ·
                Commitment {{ number_format(($weights['commitment'] ?? 0) * 100, 0) }}% ·
                Quality {{ number_format(($weights['quality'] ?? 0) * 100, 0) }}%
            </div>
        </div>
    </div>

    <div class="table-responsive card border-0 shadow-sm">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th class="text-end">Composite</th>
                    <th class="text-end">Outcome</th>
                    <th class="text-end">Reach</th>
                    <th class="text-end">Contribution</th>
                    <th class="text-end">Commitment</th>
                    <th class="text-end">Quality</th>
                    <th class="text-end">Cases Worked</th>
                    <th class="text-end">Touches*</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($snapshots as $snapshot)
                    @php
                        $inputs = $snapshot->inputs ?? [];
                    @endphp
                    <tr>
                        <td>{{ $snapshot->user?->name ?? ('#'.$snapshot->user_id) }}</td>
                        <td class="text-end fw-semibold">{{ number_format((float) $snapshot->composite_score, 1) }}</td>
                        <td class="text-end">{{ $snapshot->outcome_score }}</td>
                        <td class="text-end">{{ $snapshot->reach_score }}</td>
                        <td class="text-end">{{ $snapshot->contribution_score }}</td>
                        <td class="text-end">{{ $snapshot->commitment_score }}</td>
                        <td class="text-end">{{ $snapshot->quality_score }}</td>
                        <td class="text-end">{{ (int) ($inputs['cases_worked'] ?? 0) }}</td>
                        <td class="text-end">{{ (int) ($inputs['customer_touches'] ?? 0) }}</td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary"
                               href="{{ route('admin.performance-intelligence.show', ['userId' => $snapshot->user_id, 'date' => $date->toDateString()]) }}">
                                Explain
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="text-muted text-center py-4">
                            No snapshots for {{ $date->toDateString() }}.
                            Use “Recalculate snapshots” to generate shadow scores.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="small text-muted mt-2 mb-0">
        * Customer Touches is a diagnostic input only — not used as the Contribution pillar score.
        This table is sorted by composite for owner review; it is not an employee leaderboard.
    </p>
@endsection
