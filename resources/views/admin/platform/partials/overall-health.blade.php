@props([
    'overallHealth',
])

@php
    /** @var \App\Data\Platform\PlatformOverallHealth $overallHealth */
@endphp

<div
    class="platform-overall-health mb-3"
    data-platform-overall-health
    data-status="{{ $overallHealth->status->value }}"
>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 py-2 px-3 border rounded-3 bg-body-tertiary">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="text-muted small text-uppercase fw-semibold">Overall Platform Health</span>
            <span class="badge text-bg-{{ $overallHealth->status->badgeClass() }}">{{ $overallHealth->statusLabel }}</span>
            @if($overallHealth->scorePercent !== null)
                <span class="small fw-semibold">{{ $overallHealth->scorePercent }}%</span>
            @endif
            @if($overallHealth->stale)
                <span class="badge text-bg-secondary">Stale · retry pending</span>
            @endif
        </div>
        <div class="text-muted small">
            @if($overallHealth->updatedAt)
                Last Updated {{ \App\Support\AppDateFormatter::format($overallHealth->updatedAt, 'g:i A') }}
            @elseif(! $overallHealth->available)
                Waiting for background refresh
            @else
                —
            @endif
        </div>
    </div>
    @if($overallHealth->summary !== '')
        <p class="text-muted small mb-0 mt-1">{{ $overallHealth->summary }}</p>
    @endif
</div>
