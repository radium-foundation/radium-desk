@props([
    'badge',
])

@php
    $label = match ($badge['label'] ?? '') {
        'Follow-up Required' => 'Awaiting Update',
        default => $badge['label'] ?? 'Scheduled',
    };

    $dotClass = match ($badge['label'] ?? '') {
        'Starting Soon' => 'appointment-status-dot--starting-soon',
        'Due Now' => 'appointment-status-dot--due-now',
        'Follow-up Required' => 'appointment-status-dot--awaiting-update',
        'Missed' => 'appointment-status-dot--missed',
        default => 'appointment-status-dot--scheduled',
    };

    $tooltip = filled($badge['schedule_summary'] ?? null)
        ? $badge['schedule_summary'].' — '.$label
        : ($badge['title'] ?? $label);
@endphp

<span @class(['appointment-status-dot', $dotClass])
      title="{{ $tooltip }}"
      aria-label="{{ $tooltip }}"
      role="img"></span>
