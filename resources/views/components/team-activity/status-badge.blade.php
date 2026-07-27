@props([
    'status',
    'label',
])

<span {{ $attributes->class([
    'team-activity-status',
    'team-activity-status-pill',
    'team-activity-status-pill--'.$status,
]) }}>
    <span class="team-activity-status__dot" aria-hidden="true"></span>
    <span class="team-activity-status__label">{{ $label }}</span>
</span>
