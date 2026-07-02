@props([
    'insights' => [],
])

<section class="customer-360-ai-advisor"
         aria-labelledby="customer-360-ai-advisor-heading">
    <div class="customer-360-ai-advisor-header">
        <h3 class="customer-360-ai-card-title" id="customer-360-ai-advisor-heading">IRA Advisor</h3>
        <span class="customer-360-ai-badge">Recommendations only</span>
    </div>

    @if($insights === [])
        <p class="customer-360-ai-text customer-360-ai-text--muted">No incident-specific advisory insights at this time.</p>
    @else
        <ul class="customer-360-ai-advisor-list">
            @foreach($insights as $insight)
                <li class="customer-360-ai-advisor-item customer-360-ai-advisor-item--{{ $insight->severity->value }}">
                    <div class="customer-360-ai-advisor-item-header">
                        <strong>{{ $insight->title }}</strong>
                        <span class="customer-360-ai-advisor-category">{{ $insight->category->label() }}</span>
                    </div>
                    <p class="customer-360-ai-advisor-recommendation">{{ $insight->recommendation }}</p>
                    <div class="customer-360-ai-advisor-meta">
                        <span class="customer-360-ai-risk-level">{{ $insight->severity->label() }}</span>
                        <span>{{ $insight->confidence->label() }} confidence ({{ $insight->confidenceScore }}%)</span>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
