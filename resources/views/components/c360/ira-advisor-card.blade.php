@props([
    'viewModel' => [],
])

@php
    $recommendedAction = $viewModel['recommended_action'] ?? null;
    $confidence = $viewModel['confidence'] ?? ['level' => 'medium', 'label' => 'Medium'];
    $reasons = is_array($viewModel['reasons'] ?? null) ? $viewModel['reasons'] : [];
    $secondaryActions = is_array($viewModel['secondary_actions'] ?? null) ? $viewModel['secondary_actions'] : [];
    $confidenceLevel = $confidence['level'] ?? 'medium';
    $signalCount = count($reasons);
    $confidencePercent = match ($confidenceLevel) {
        'high' => min(95, 82 + ($signalCount * 2)),
        'low' => max(28, 35 + $signalCount),
        default => min(88, 58 + ($signalCount * 3)),
    };
@endphp

@if(is_array($recommendedAction))
<section {{ $attributes->merge(['class' => 'c360-ira-advisor-card c360-ira-command-center c360-ira-command-center--advisor']) }}
         data-customer-360-section="ira-advisor-card"
         aria-labelledby="c360-ira-advisor-card-heading">
    <div class="c360-ira-command-center-glow" aria-hidden="true"></div>

    <div class="c360-ira-command-center-header">
        <h3 class="c360-ira-command-center-heading" id="c360-ira-advisor-card-heading">
            <i class="bi bi-stars c360-ira-sparkle" aria-hidden="true"></i>
            IRA advisor
        </h3>
        <span class="c360-ira-readonly-badge">Rule-based</span>
    </div>

    <div class="c360-ira-command-center-body">
        <div class="c360-ira-section">
            <h4 class="c360-ira-section-label">
                <i class="bi bi-stars" aria-hidden="true"></i>
                Recommendation
            </h4>
            <p class="c360-ira-recommendation-text">
                {{ $recommendedAction['label'] ?? '' }}
            </p>
        </div>

        <div class="c360-ira-section c360-ira-section--action">
            <h4 class="c360-ira-section-label">Primary action</h4>
            <div class="c360-ira-primary-action c360-ira-primary-action--display" role="status">
                <i class="bi {{ $recommendedAction['icon'] ?? 'bi-lightbulb' }}" aria-hidden="true"></i>
                <span>{{ $recommendedAction['label'] ?? '' }}</span>
            </div>
        </div>

        <div class="c360-ira-section">
            <x-c360.ira-confidence
                :level="$confidenceLevel"
                :label="$confidence['label'] ?? 'Medium'"
                :percent="$confidencePercent"
                :signal-count="$signalCount"
            />
        </div>

        @if($reasons !== [])
            <div class="c360-ira-section">
                <h4 class="c360-ira-section-label">Why this recommendation</h4>
                <ul class="c360-ira-why-list">
                    @foreach($reasons as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($secondaryActions !== [])
            <details class="c360-ira-collapse" data-c360-ira-collapse>
                <summary class="c360-ira-collapse-summary">
                    <span>More actions</span>
                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                </summary>
                <div class="c360-ira-collapse-body">
                    <ul class="c360-ira-secondary-list" role="list">
                        @foreach($secondaryActions as $action)
                            <li class="c360-ira-secondary-item"
                                role="listitem"
                                data-c360-advisor-action="{{ $action['key'] ?? '' }}">
                                <i class="bi {{ $action['icon'] ?? 'bi-circle' }}" aria-hidden="true"></i>
                                <span>{{ $action['label'] ?? '' }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </details>
        @endif
    </div>
</section>
@else
<x-c360.empty-state
    icon="bi-lightbulb"
    title="No IRA recommendation"
    description="Rule-based advisor will suggest next steps when this case matches a playbook."
    action-label="Open IRA AI"
    action-icon="bi-stars"
    data-c360-empty-open-tab="ai-assistant"
    class="c360-ira-advisor-empty"
    data-customer-360-section="ira-advisor-card"
/>
@endif
