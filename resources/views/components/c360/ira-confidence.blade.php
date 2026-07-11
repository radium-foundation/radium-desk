@props([
    'level' => 'medium',
    'label' => 'Medium',
    'percent' => 60,
    'signalCount' => 0,
])

@php
    $percent = max(0, min(100, (int) $percent));
    $signalCount = max(0, (int) $signalCount);
    $caption = $signalCount > 0
        ? 'Based on '.$signalCount.' verified signal'.($signalCount === 1 ? '' : 's')
        : 'Based on available case signals';
@endphp

<div {{ $attributes->merge(['class' => 'c360-ira-confidence']) }}>
    <div class="c360-ira-confidence-header">
        <h3 class="c360-ira-section-label mb-0">Confidence</h3>
        <span class="c360-ira-confidence-percent" aria-hidden="true">{{ $percent }}%</span>
    </div>

    <div class="c360-ira-confidence-bar"
         role="progressbar"
         aria-valuenow="{{ $percent }}"
         aria-valuemin="0"
         aria-valuemax="100"
         aria-label="IRA confidence level">
        <span class="c360-ira-confidence-fill c360-ira-confidence-fill--{{ $level }}"
              style="width: {{ $percent }}%"></span>
    </div>

    <div class="c360-ira-confidence-meta">
        <span @class([
            'c360-ira-confidence-label',
            'c360-ira-confidence-label--' . $level,
        ])>{{ $label }}</span>
        <span class="c360-ira-confidence-caption">{{ $caption }}</span>
    </div>
</div>
