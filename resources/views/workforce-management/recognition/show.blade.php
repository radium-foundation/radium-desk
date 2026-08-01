@extends('layouts.app')

@section('title', 'Recognition Review')

@section('content')
    @php
        /** @var \App\Models\WorkRecognitionReview $review */
        $snapshot = $review->evidence_snapshot ?? [];
        $signals = $snapshot['contribution']['signals'] ?? [];
        $timeline = $snapshot['timeline'] ?? [];
    @endphp

    <div class="workforce-management-page">
        <header class="wm-page-header">
            <div class="wm-page-header__eyebrow">
                <a href="{{ route('workforce-management.recognition.index', ['month' => $review->work_date->format('Y-m')]) }}">Work Recognition</a>
            </div>
            <h1 class="wm-page-header__title">{{ $review->user?->name }} · {{ $review->work_date->toDateString() }}</h1>
            <p class="wm-page-header__subtitle">{{ $review->day_context->label() }} · pack {{ $review->department_pack }}</p>
        </header>

        @include('workforce-management.partials.workspace-nav', ['active' => 'recognition'])

        <div class="row g-3">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h2 class="h6">Evidence</h2>
                        <p class="mb-2">
                            Login {{ number_format(($review->login_seconds ?? 0) / 3600, 1) }}h ·
                            Productive {{ number_format(($review->productive_seconds ?? 0) / 3600, 1) }}h
                        </p>
                        <ul class="small mb-3">
                            @forelse (($snapshot['evidence_summary'] ?? []) as $line)
                                <li>{{ $line }}</li>
                            @empty
                                <li class="text-muted">No business signal totals.</li>
                            @endforelse
                        </ul>

                        <h3 class="h6">Signals</h3>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm">
                                <thead><tr><th>Signal</th><th>Value</th></tr></thead>
                                <tbody>
                                    @foreach ($signals as $signal)
                                        @continue(!($signal['available'] ?? false) || (float) ($signal['value'] ?? 0) <= 0)
                                        <tr>
                                            <td>{{ $signal['label'] ?? $signal['id'] }}</td>
                                            <td>{{ $signal['value'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <h3 class="h6">Timeline excerpt</h3>
                        <ul class="small mb-0">
                            @forelse ($timeline as $entry)
                                <li>{{ $entry['time'] ?? '' }} — {{ $entry['label'] ?? '' }}</li>
                            @empty
                                <li class="text-muted">No timeline entries.</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <h2 class="h6">IRA recommendation</h2>
                        <p class="mb-1"><strong>{{ $review->ira_recommendation->label() }}</strong> · score {{ $review->ira_score }}</p>
                        <p class="small text-muted mb-0">{{ $review->ira_rationale }}</p>
                    </div>
                </div>

                @if ($review->isPending() && $canReview)
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <h2 class="h6">Manager decision</h2>
                            <form method="POST" action="{{ route('workforce-management.recognition.decide', $review) }}">
                                @csrf
                                <div class="mb-2">
                                    <label class="form-label" for="decision">Decision</label>
                                    <select name="decision" id="decision" class="form-select" required>
                                        @foreach ($recommendations as $option)
                                            <option value="{{ $option->value }}" @selected($option === $review->ira_recommendation)>{{ $option->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="decision_reason">Reason (required when overriding IRA)</label>
                                    <textarea name="decision_reason" id="decision_reason" class="form-control" rows="3" maxlength="2000">{{ old('decision_reason') }}</textarea>
                                </div>
                                <button type="submit" class="btn btn-primary">Confirm decision</button>
                            </form>

                            <form method="POST" action="{{ route('workforce-management.recognition.refresh', $review) }}" class="mt-3">
                                @csrf
                                <button type="submit" class="btn btn-outline-secondary btn-sm">Refresh evidence</button>
                            </form>
                        </div>
                    </div>
                @elseif (! $review->isPending())
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <h2 class="h6">Decision</h2>
                            <p class="mb-1"><strong>{{ $review->decision?->label() }}</strong></p>
                            <p class="small text-muted mb-1">By {{ $review->decider?->name }} · {{ $review->decided_at?->timezone(config('app.timezone'))->format('M j, Y H:i') }}</p>
                            @if ($review->decision_reason)
                                <p class="small mb-0">{{ $review->decision_reason }}</p>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
