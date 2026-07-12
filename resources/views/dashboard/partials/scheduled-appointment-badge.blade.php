@props([
    'badge',
    'compact' => false,
])

@php
    $toneClass = match ($badge['tone'] ?? 'info') {
        'danger' => 'dashboard-appointment-badge--danger',
        'warning' => 'dashboard-appointment-badge--warning',
        default => 'dashboard-appointment-badge--info',
    };
@endphp

@if($compact)
    <span @class(['dashboard-appointment-badge-dot', $toneClass])
          data-bs-toggle="tooltip"
          data-bs-placement="top"
          data-bs-title="{{ $badge['label'] }}"
          aria-label="{{ $badge['label'] }}"
          role="img">{{ $badge['compact_symbol'] }}</span>
@else
    <span @class([
            'badge',
            'rounded-pill',
            'border',
            'dashboard-appointment-badge',
            $toneClass,
        ])
          title="{{ $badge['title'] }}">{{ $badge['label'] }}</span>
@endif
