@props([
    'insights' => [],
])

<section class="mb-4" aria-labelledby="ira-performance-insights-heading">
    <h2 id="ira-performance-insights-heading" class="h5 mb-3">Ira Insights</h2>

    @if($insights === [])
        <div class="card border-0 shadow-sm">
            <div class="card-body text-muted small mb-0">
                No contextual insights for this period yet.
            </div>
        </div>
    @else
        <div class="d-flex flex-column gap-2">
            @foreach($insights as $insight)
                <div class="alert alert-{{ $insight->tone->badgeClass() }} mb-0 py-2 px-3">
                    {{ $insight->message }}
                </div>
            @endforeach
        </div>
    @endif
</section>
