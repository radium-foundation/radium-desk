@props([
    'insights' => [],
])

<section class="mb-4" aria-labelledby="operations-advisor-heading">
    <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
        <h2 id="operations-advisor-heading" class="h5 mb-0">IRA Advisor</h2>
        <span class="badge text-bg-light border">Recommendations only</span>
    </div>

    @if($insights === [])
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <p class="text-muted mb-0">No advisory insights at this time. Operational metrics look stable.</p>
            </div>
        </div>
    @else
        <div class="row g-3">
            @foreach($insights as $insight)
                <div class="col-md-6 col-xl-4">
                    <a href="{{ $insight->actionUrl ?? '#' }}"
                       class="operations-advisor-card operations-advisor-card--{{ $insight->severity->value }} text-decoration-none">
                        <div class="operations-advisor-card-icon" aria-hidden="true">⚠</div>
                        <div class="operations-advisor-card-body">
                            <h3 class="operations-advisor-card-title">{{ $insight->title }}</h3>
                            <p class="operations-advisor-card-category">{{ $insight->category->label() }}</p>
                            <p class="operations-advisor-card-recommendation">{{ $insight->recommendation }}</p>
                            <div class="operations-advisor-card-meta">
                                <span class="badge bg-{{ match($insight->severity) {
                                    \App\Enums\AI\AIRiskLevel::High => 'danger',
                                    \App\Enums\AI\AIRiskLevel::Medium => 'warning',
                                    \App\Enums\AI\AIRiskLevel::Low => 'secondary',
                                } }}">{{ $insight->severity->label() }}</span>
                                <span class="text-muted small">{{ $insight->confidence->label() }} confidence</span>
                            </div>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    @endif
</section>
