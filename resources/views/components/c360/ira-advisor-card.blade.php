@props([
    'viewModel' => [],
])

@php
    $recommendedAction = $viewModel['recommended_action'] ?? null;
    $confidence = $viewModel['confidence'] ?? ['level' => 'medium', 'label' => 'Medium'];
    $reasons = is_array($viewModel['reasons'] ?? null) ? $viewModel['reasons'] : [];
    $secondaryActions = is_array($viewModel['secondary_actions'] ?? null) ? $viewModel['secondary_actions'] : [];
    $confidenceLevel = $confidence['level'] ?? 'medium';
@endphp

@if(is_array($recommendedAction))
<section {{ $attributes->merge(['class' => 'c360-ira-advisor-card']) }}
         data-customer-360-section="ira-advisor-card"
         aria-labelledby="c360-ira-advisor-card-heading">
    <div class="c360-ira-advisor-card-header">
        <div class="c360-ira-advisor-card-heading-wrap">
            <h3 class="c360-ira-advisor-card-heading" id="c360-ira-advisor-card-heading">IRA Advisor</h3>
            <p class="c360-ira-advisor-card-subtitle mb-0">Rule-based recommendation for the next agent action.</p>
        </div>
        <span class="c360-ira-advisor-card-badge">Recommendations only</span>
    </div>

    <div class="c360-ira-advisor-recommendation">
        <div class="c360-ira-advisor-primary">
            <span class="c360-ira-advisor-primary-icon" aria-hidden="true">
                <i class="bi {{ $recommendedAction['icon'] ?? 'bi-lightbulb' }}"></i>
            </span>
            <div class="c360-ira-advisor-primary-content">
                <span class="c360-ira-advisor-primary-label">Recommended Action</span>
                <strong class="c360-ira-advisor-primary-action">{{ $recommendedAction['label'] ?? '' }}</strong>
            </div>
            <span @class([
                'c360-ira-advisor-confidence',
                'c360-ira-advisor-confidence--high' => $confidenceLevel === 'high',
                'c360-ira-advisor-confidence--medium' => $confidenceLevel === 'medium',
                'c360-ira-advisor-confidence--low' => $confidenceLevel === 'low',
            ])>
                {{ $confidence['label'] ?? 'Medium' }} confidence
            </span>
        </div>

        @if($reasons !== [])
            <div class="c360-ira-advisor-reasons">
                <h4 class="c360-ira-advisor-reasons-heading">Supporting Reasons</h4>
                <ul class="c360-ira-advisor-reasons-list">
                    @foreach($reasons as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($secondaryActions !== [])
            <div class="c360-ira-advisor-secondary">
                <h4 class="c360-ira-advisor-secondary-heading">Secondary Actions</h4>
                <ul class="c360-ira-advisor-secondary-list" role="list">
                    @foreach($secondaryActions as $action)
                        <li class="c360-ira-advisor-secondary-item"
                            role="listitem"
                            data-c360-advisor-action="{{ $action['key'] ?? '' }}">
                            <i class="bi {{ $action['icon'] ?? 'bi-circle' }}" aria-hidden="true"></i>
                            <span>{{ $action['label'] ?? '' }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</section>
@endif
