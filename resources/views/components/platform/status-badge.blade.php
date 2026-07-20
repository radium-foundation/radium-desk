@props([
    'status',
    'label' => null,
])

@php
    $statusEnum = $status instanceof \App\Enums\PlatformHealthStatus
        ? $status
        : \App\Enums\PlatformHealthStatus::tryFrom((string) $status);

    $badgeClass = $statusEnum?->badgeClass() ?? 'secondary';
    $statusLabel = $label ?? $statusEnum?->label() ?? 'Unknown';
@endphp

<span {{ $attributes->class(['badge', 'bg-'.$badgeClass]) }}>{{ $statusLabel }}</span>
