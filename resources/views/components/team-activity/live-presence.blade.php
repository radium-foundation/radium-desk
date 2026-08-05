@props([
    'status',
    'code',
    'duration' => null,
    'late' => null,
    'performanceBadges' => [],
    'title' => null,
    'ariaLabel' => null,
])

@php
    $durationValue = is_string($duration) ? trim($duration) : '';
    $lateDuration = is_string($late) ? trim($late) : '';
    $titleText = is_string($title) ? trim($title) : '';
@endphp

<div {{ $attributes->class(['team-activity-live-presence', 'team-activity-live-presence--compact']) }}
     @if(filled($ariaLabel)) aria-label="{{ $ariaLabel }}" @endif
     @if($titleText !== '') title="{{ $titleText }}" @endif>
    <span @class([
        'team-activity-status',
        'team-activity-status-pill',
        'team-activity-status-pill--'.$status,
        'team-activity-live-presence__primary',
    ]) aria-hidden="true">
        <span class="team-activity-status__dot"></span>
        <span class="team-activity-live-presence__code">{{ $code }}</span>
        @if($durationValue !== '')
            <sup class="team-activity-live-presence__duration"
                 title="{{ $durationValue }}">
                <x-team-activity.duration :value="$durationValue" />
            </sup>
        @endif
    </span>

    @if($lateDuration !== '')
        <span class="team-activity-operational-indicator team-activity-operational-indicator--late team-activity-live-presence__late"
              title="{{ $lateDuration }} late"
              aria-hidden="true">
            <span class="team-activity-operational-indicator__late-mark">L</span><sup class="team-activity-operational-indicator__late-sup"><x-team-activity.duration :value="$lateDuration" /></sup>
        </span>
    @endif

    <x-team-activity.performance-badges
        :badges="$performanceBadges"
        class="team-activity-live-presence__performance-badges" />
</div>
