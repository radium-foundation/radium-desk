@props([
    'badge',
])

@php
    $label = match ($badge['label'] ?? '') {
        'Follow-up Required' => 'Awaiting Update',
        default => $badge['label'] ?? 'Scheduled',
    };

    $pillClass = match ($badge['label'] ?? '') {
        'Starting Soon' => 'appointment-status-pill--starting-soon',
        'Due Now' => 'appointment-status-pill--due-now',
        'Follow-up Required' => 'appointment-status-pill--awaiting-update',
        'Missed' => 'appointment-status-pill--missed',
        default => 'appointment-status-pill--scheduled',
    };

    $tooltip = filled($badge['schedule_summary'] ?? null)
        ? $badge['schedule_summary'].' — '.$label
        : ($badge['title'] ?? $label);
@endphp

<span @class(['appointment-status-pill', $pillClass])
      title="{{ $tooltip }}"
      aria-label="{{ $tooltip }}">
    {{ $label }}
</span>
