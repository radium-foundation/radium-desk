@props(['status'])

@php
    $class = match ($status) {
        \App\Enums\IncidentStatus::Open => 'text-bg-warning',
        \App\Enums\IncidentStatus::InProgress => 'text-bg-info',
        \App\Enums\IncidentStatus::Resolved => 'text-bg-success',
        \App\Enums\IncidentStatus::Closed => 'text-bg-secondary',
    };
@endphp

<span class="badge {{ $class }}">{{ $status->label() }}</span>
