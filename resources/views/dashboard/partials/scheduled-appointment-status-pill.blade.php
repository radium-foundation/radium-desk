@props([
    'badge',
])

@php
    $label = match ($badge['label'] ?? '') {
        'Follow-up Required' => 'Awaiting Update',
        default => $badge['label'] ?? 'Scheduled',
    };

    $iconClass = match ($badge['label'] ?? '') {
        'Starting Soon' => 'dashboard-appointment-icon--starting-soon',
        'Due Now' => 'dashboard-appointment-icon--due-now',
        'Follow-up Required' => 'dashboard-appointment-icon--awaiting-update',
        'Missed' => 'dashboard-appointment-icon--missed',
        default => 'dashboard-appointment-icon--scheduled',
    };

    $symbol = $badge['compact_symbol'] ?? '📅';

    $tooltip = filled($badge['schedule_summary'] ?? null)
        ? $badge['schedule_summary'].' — '.$label
        : ($badge['title'] ?? $label);
@endphp

<span @class(['dashboard-appointment-icon', $iconClass])
      title="{{ $tooltip }}"
      aria-label="{{ $tooltip }}"
      role="img">{{ $symbol }}</span>
