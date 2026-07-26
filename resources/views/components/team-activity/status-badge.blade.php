@props([
    'status',
    'label',
])

<span {{ $attributes->class([
    'team-activity-status-pill',
    'team-activity-status-pill--'.$status,
]) }}>
    {{ $label }}
</span>
