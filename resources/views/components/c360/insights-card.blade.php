@props([
    'insights' => [],
])

@if(count($insights) > 0)
<section {{ $attributes->merge(['class' => 'c360-insights-card']) }}
         data-customer-360-section="customer-insights-card"
         aria-labelledby="c360-insights-card-heading">
    <div class="c360-insights-card-header">
        <h3 class="c360-insights-card-heading" id="c360-insights-card-heading">Customer insights</h3>
        <p class="c360-insights-card-subtitle mb-0">Deterministic signals from customer history.</p>
    </div>

    <ul class="c360-insights-list" role="list">
        @foreach($insights as $insight)
            <li class="c360-insight" role="listitem" data-c360-insight="{{ $insight['key'] ?? '' }}">
                <span class="c360-insight-icon" aria-hidden="true">
                    <i class="bi {{ $insight['icon'] ?? 'bi-lightbulb' }}"></i>
                </span>
                <div class="c360-insight-content">
                    <span class="c360-insight-label">{{ $insight['label'] ?? '' }}</span>
                    <span class="c360-insight-description">{{ $insight['description'] ?? '' }}</span>
                </div>
            </li>
        @endforeach
    </ul>
</section>
@endif
