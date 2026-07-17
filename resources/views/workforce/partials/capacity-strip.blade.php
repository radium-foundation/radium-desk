@props([
    'capacity' => [],
])

<section class="workforce360-capacity mb-4" aria-label="Team capacity">
    <div class="row g-3">
        @foreach($capacity as $item)
            <div class="col-6 col-lg-3">
                <div @class([
                    'workforce360-capacity-card card border-0 shadow-sm h-100',
                    'workforce360-capacity-card--' . ($item['tone'] ?? 'info'),
                ])>
                    <div class="card-body">
                        <div class="workforce360-capacity-card__value h3 mb-1">{{ number_format((int) ($item['value'] ?? 0)) }}</div>
                        <div class="text-muted small">{{ $item['label'] ?? '' }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</section>
