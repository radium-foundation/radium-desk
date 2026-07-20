@props([
    'metric',
])

@php
    $label = is_array($metric) ? ($metric['label'] ?? '') : $metric->label;
    $value = is_array($metric) ? ($metric['value'] ?? '') : $metric->value;
    $detail = is_array($metric) ? ($metric['detail'] ?? null) : $metric->detail;
    $status = is_array($metric)
        ? ($metric['status'] ?? null)
        : $metric->status?->value;
    $badgeClass = is_array($metric)
        ? ($metric['badge_class'] ?? null)
        : $metric->status?->badgeClass();
@endphp

<div class="d-flex justify-content-between align-items-start gap-3 py-2 border-bottom platform-metric-row">
    <div class="min-w-0">
        <div class="fw-semibold small">{{ $label }}</div>
        @if(filled($detail))
            <div class="text-muted small">{{ $detail }}</div>
        @endif
    </div>
    <div class="text-end flex-shrink-0">
        @if(filled($status))
            <x-platform.status-badge :status="$status" :label="$value" />
        @else
            <span class="small fw-semibold">{{ $value }}</span>
        @endif
    </div>
</div>
