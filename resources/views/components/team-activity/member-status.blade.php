@props([
    'status',
    'label',
    'context' => null,
    'ariaLabel' => null,
])

<span {{ $attributes->class([
    'team-activity-member-status',
    'team-activity-member-status--'.$status,
]) }}
      @if(filled($ariaLabel)) aria-label="{{ $ariaLabel }}" @endif>
    <span class="team-activity-member-status__line">
        <span class="team-activity-member-status__dot" aria-hidden="true"></span>
        <span class="team-activity-member-status__label">{{ $label }}</span>
        @if(filled($context))
            <span class="team-activity-member-status__sep" aria-hidden="true">·</span>
            <span class="team-activity-member-status__context">{{ $context }}</span>
        @endif
    </span>
</span>
