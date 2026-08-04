@props([
    'status',
    'label',
    'context' => null,
    'late' => null,
    'ariaLabel' => null,
])

@php
    $lateDuration = is_string($late) ? trim($late) : '';
@endphp

<span {{ $attributes->class([
    'team-activity-member-status',
    'team-activity-member-status--'.$status,
]) }}
      @if(filled($ariaLabel)) aria-label="{{ $ariaLabel }}" @endif>
    <span class="team-activity-member-status__line">
        <span class="team-activity-member-status__dot" aria-hidden="true"></span>
        <span class="team-activity-member-status__label">{{ $label }}</span>
        @if($lateDuration !== '')
            <span class="team-activity-member-status__sep" aria-hidden="true">·</span>
            <span class="team-activity-member-status__late"
                  title="{{ $lateDuration }} late"
                  aria-hidden="true">
                <span class="team-activity-member-status__late-mark">L</span><sup class="team-activity-member-status__late-sup"><x-team-activity.duration :value="$lateDuration" /></sup>
            </span>
        @endif
        @if(filled($context))
            <span class="team-activity-member-status__sep" aria-hidden="true">·</span>
            <span class="team-activity-member-status__context">
                <x-team-activity.duration :value="$context" />
            </span>
        @endif
    </span>
</span>
