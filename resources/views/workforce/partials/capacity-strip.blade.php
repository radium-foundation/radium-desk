@props([
    'capacity' => [],
])

@php
    $workforceToday = $capacity['workforce_today'] ?? [];
    $attentionRequired = $capacity['attention_required'] ?? [];
@endphp

<section class="workforce360-capacity mb-4" aria-label="Team capacity">
    @if($workforceToday !== [])
        <div class="workforce360-capacity-section mb-3">
            <h2 class="workforce360-capacity-section__title h6 mb-2">Workforce Today</h2>
            <div class="row g-3">
                @foreach($workforceToday as $item)
                    <div class="col-6 col-lg-3">
                        <div @class([
                            'workforce360-capacity-card card border-0 shadow-sm h-100',
                            'workforce360-capacity-card--' . ($item['tone'] ?? 'info'),
                        ])>
                            <div class="card-body py-3">
                                <div class="workforce360-capacity-card__value h3 mb-1">{{ number_format((int) ($item['value'] ?? 0)) }}</div>
                                <div class="text-muted small">{{ $item['label'] ?? '' }}</div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if($attentionRequired !== [])
        <div class="workforce360-capacity-section">
            <h2 class="workforce360-capacity-section__title h6 mb-2">Attention Required</h2>
            <div class="row g-3">
                @foreach($attentionRequired as $item)
                    <div class="col-6 col-lg-3">
                        <div @class([
                            'workforce360-capacity-card card border-0 shadow-sm h-100',
                            'workforce360-capacity-card--' . ($item['tone'] ?? 'info'),
                        ])>
                            <div class="card-body py-3">
                                <div class="workforce360-capacity-card__value h3 mb-1">{{ number_format((int) ($item['value'] ?? 0)) }}</div>
                                <div class="text-muted small">{{ $item['label'] ?? '' }}</div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</section>
