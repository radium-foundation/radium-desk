@props([
    'briefing' => null,
    'formatted' => null,
    'reasoningProvider' => 'rule_based',
])

@php
    $healthClass = match ($briefing?->healthStatus ?? 'healthy') {
        'healthy' => 'success',
        'critical' => 'danger',
        default => 'warning',
    };

    $healthLabel = match ($briefing?->healthStatus ?? 'healthy') {
        'healthy' => 'Healthy',
        'critical' => 'Critical',
        default => 'Needs Attention',
    };
@endphp

<section class="operations-ira-compact mb-0" id="ira-operations-briefing" aria-labelledby="ira-briefing-heading">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom-0 pb-0">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h2 id="ira-briefing-heading" class="h5 mb-1">Ira Today</h2>
                    <p class="text-muted small mb-0">Operational intelligence from Ira Operations Brain</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge text-bg-{{ $healthClass }}">{{ $healthLabel }}</span>
                    <span class="badge text-bg-light border text-muted">{{ str($reasoningProvider)->headline() }}</span>
                </div>
            </div>
        </div>

        @if($briefing === null || $formatted === null)
            <div class="card-body">
                <p class="text-muted mb-0">Ira is preparing today&apos;s briefing.</p>
            </div>
        @else
            <div class="card-body pt-3">
                @include('admin.operations.partials.ira-briefing-sections', [
                    'formatted' => $formatted,
                    'compact' => true,
                ])

                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button
                        type="button"
                        class="btn btn-link btn-sm p-0 text-muted"
                        data-operations-tab-target="#operations-tab-today"
                    >
                        Open in Today tab
                    </button>
                </div>
            </div>
        @endif
    </div>
</section>
