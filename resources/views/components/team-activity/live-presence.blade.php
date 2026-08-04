@props([
    'status',
    'label',
    'late' => null,
    'secondary' => null,
    'ariaLabel' => null,
])

@php
    $lateDuration = is_string($late) ? trim($late) : '';
    $secondaryText = is_string($secondary) ? trim($secondary) : '';
@endphp

<div {{ $attributes->class(['team-activity-status-stack', 'team-activity-live-presence']) }}
     @if(filled($ariaLabel)) aria-label="{{ $ariaLabel }}" @endif>
    <x-team-activity.status-badge :status="$status" :label="$label" />

    @if($lateDuration !== '' || $secondaryText !== '')
        <div class="team-activity-operational-indicators" aria-hidden="true">
            @if($lateDuration !== '')
                <span class="team-activity-operational-indicator team-activity-operational-indicator--late"
                      title="{{ $lateDuration }} late">
                    <span class="team-activity-operational-indicator__late-mark">L</span><sup class="team-activity-operational-indicator__late-sup"><x-team-activity.duration :value="$lateDuration" /></sup>
                </span>
            @endif
        </div>
    @endif

    @if($secondaryText !== '')
        <span class="team-activity-status-note" aria-hidden="true">{{ $secondaryText }}</span>
    @endif
</div>
