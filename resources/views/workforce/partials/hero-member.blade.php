@props([
    'hero' => [],
    'userName' => '',
    'isSelf' => false,
    'teamUrl' => null,
])

@php
    $score = $hero['score'] ?? [];
    $chips = $hero['status_chips'] ?? [];
@endphp

<section class="workforce360-hero card border-0 shadow-sm mb-4" aria-label="Individual workforce summary">
    <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
            <div>
                @if($teamUrl)
                    <a href="{{ $teamUrl }}" class="text-decoration-none small text-muted d-inline-flex align-items-center gap-1 mb-2">
                        <i class="bi bi-arrow-left"></i>
                        <span>Team</span>
                    </a>
                @endif
                <div class="text-muted small text-uppercase fw-semibold mb-1">
                    {{ $isSelf ? 'My Workforce' : 'Individual Workforce' }}
                </div>
                <h1 class="h3 mb-1">{{ $userName }}</h1>
                <div class="workforce360-hero__summary text-muted">{{ $hero['headline'] ?? '—' }}</div>
            </div>
            <div class="workforce360-score workforce360-score--placeholder text-center">
                <div class="workforce360-score__value">—</div>
                <div class="workforce360-score__label">{{ $score['label'] ?? 'Coming in Sprint 3' }}</div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2">
            @foreach($chips as $chip)
                <span @class([
                    'workforce360-status-chip',
                    'workforce360-status-chip--' . ($chip['tone'] ?? 'info'),
                ])>{{ $chip['label'] ?? '' }}</span>
            @endforeach
        </div>
    </div>
</section>
