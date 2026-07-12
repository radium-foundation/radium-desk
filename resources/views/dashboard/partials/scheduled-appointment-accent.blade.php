@props([
    'badge',
])

@php
    $accentLabel = match ($badge['label'] ?? '') {
        'Follow-up Required' => 'Awaiting Update',
        default => $badge['label'] ?? '',
    };

    $accentClass = match ($badge['label'] ?? '') {
        'Starting Soon' => 'dashboard-appointment-accent--starting-soon',
        'Due Now' => 'dashboard-appointment-accent--due-now',
        'Follow-up Required' => 'dashboard-appointment-accent--awaiting-update',
        'Missed' => 'dashboard-appointment-accent--missed',
        default => null,
    };
@endphp

@if($accentClass)
    <span @class(['dashboard-appointment-accent', $accentClass])
          data-bs-toggle="tooltip"
          data-bs-placement="top"
          data-bs-title="{{ $accentLabel }}"
          aria-label="{{ $accentLabel }}"
          role="img"></span>
@endif
