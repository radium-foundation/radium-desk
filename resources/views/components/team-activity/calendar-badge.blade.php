@props([
    'label',
])

<span {{ $attributes->class(['team-activity-calendar-pill']) }}>
    <span class="team-activity-calendar-pill__icon" aria-hidden="true">🏖</span>
    <span class="team-activity-calendar-pill__label">{{ $label }}</span>
</span>
