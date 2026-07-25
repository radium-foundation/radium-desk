@props([
    'status',
    'label' => null,
])

@php
    $statusEnum = $status instanceof \App\Enums\PlatformHealthStatus
        ? $status
        : \App\Enums\PlatformHealthStatus::tryFrom((string) $status);

    $statusLabel = $label ?? $statusEnum?->label() ?? 'Unknown';

    $tone = match ($statusEnum) {
        \App\Enums\PlatformHealthStatus::Healthy => 'success',
        \App\Enums\PlatformHealthStatus::Warning => 'warning',
        \App\Enums\PlatformHealthStatus::Critical => 'danger',
        \App\Enums\PlatformHealthStatus::Disabled => 'neutral',
        default => 'neutral',
    };
@endphp

<x-settings-center.status-pill :label="$statusLabel" :tone="$tone" size="sm" {{ $attributes }} />
