@props([
    'hero' => [],
])

@php
    $score = $hero['score'] ?? [];
    $metrics = $hero['metrics'] ?? [];
@endphp

<section class="workforce360-hero card border-0 shadow-sm mb-4" aria-label="Team workforce summary">
    <div class="card-body">
        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
            <div>
                <div class="text-muted small text-uppercase fw-semibold mb-1">Team Workforce</div>
                <div class="workforce360-hero__summary h4 mb-2">{{ $hero['summary'] ?? '—' }}</div>
                <div class="text-muted small">Live operational snapshot for attendance-tracked team members.</div>
            </div>
            <div class="workforce360-score workforce360-score--placeholder text-center">
                <div class="workforce360-score__value">—</div>
                <div class="workforce360-score__label">{{ $score['label'] ?? 'Coming in Sprint 3' }}</div>
            </div>
        </div>

        <div class="workforce360-hero-metrics d-md-none mt-3">
            <div class="row g-2">
                <div class="col-6">
                    <div class="workforce360-metric-pill">
                        <span class="workforce360-metric-pill__value">{{ $metrics['on_duty'] ?? 0 }}</span>
                        <span class="workforce360-metric-pill__label">On duty</span>
                    </div>
                </div>
                <div class="col-6">
                    <div class="workforce360-metric-pill">
                        <span class="workforce360-metric-pill__value">{{ $metrics['on_shift'] ?? 0 }}</span>
                        <span class="workforce360-metric-pill__label">On shift</span>
                    </div>
                </div>
                <div class="col-6">
                    <div class="workforce360-metric-pill">
                        <span class="workforce360-metric-pill__value">{{ $metrics['on_leave'] ?? 0 }}</span>
                        <span class="workforce360-metric-pill__label">On leave</span>
                    </div>
                </div>
                <div class="col-6">
                    <div class="workforce360-metric-pill">
                        <span class="workforce360-metric-pill__value">{{ $metrics['pending_leave'] ?? 0 }}</span>
                        <span class="workforce360-metric-pill__label">Pending leave</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
