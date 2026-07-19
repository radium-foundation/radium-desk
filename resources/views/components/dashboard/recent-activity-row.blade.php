@props([
    'item',
    'showIncident' => true,
    'threadCount' => null,
])

@php
    $indicatorVariant = match ($item->indicatorVariant) {
        'communication' => 'info',
        'automation' => 'automation',
        'remark' => 'muted',
        'error' => 'danger',
        default => $item->indicatorVariant,
    };
    $pillLabel = $item->typePill === 'IRA' ? '🤖 IRA' : $item->typePill;
    $showActor = $item->stream !== 'ira' && $item->actorName !== '' && $item->actorName !== 'IRA';
@endphp

<div @class([
        'dashboard-activity-row',
        'dashboard-activity-row--indicator-' . $indicatorVariant,
    ])>
    <div class="dashboard-activity-row-primary">
        <span class="dashboard-activity-row-dot" aria-hidden="true"></span>

        @if($showIncident && $item->incidentReference)
            @if($item->entityIncidentId)
                <button type="button"
                        class="dashboard-activity-incident"
                        data-agent-open-customer-360="{{ $item->entityIncidentId }}"
                        data-agent-customer-name="{{ $item->entityReference }}">
                    {{ $item->incidentReference }}@if($threadCount)<span class="dashboard-activity-thread-count">({{ $threadCount }})</span>@endif
                </button>
            @else
                <span class="dashboard-activity-incident dashboard-activity-incident--static">
                    {{ $item->incidentReference }}@if($threadCount)<span class="dashboard-activity-thread-count">({{ $threadCount }})</span>@endif
                </span>
            @endif
        @endif

        <span class="dashboard-activity-row-title">{{ $item->title }}</span>

        <time class="dashboard-activity-row-time"
              datetime="{{ $item->occurredAt->toIso8601String() }}"
              title="{{ $item->exactTime }}">
            {{ $item->compactTime }}
        </time>
    </div>

    @if($pillLabel || $showActor)
        <div class="dashboard-activity-row-secondary">
            @if($pillLabel)
                <span class="dashboard-activity-pill">{{ $pillLabel }}</span>
            @endif
            @if($pillLabel && $showActor)
                <span class="dashboard-activity-row-separator" aria-hidden="true">•</span>
            @endif
            @if($showActor)
                <span class="dashboard-activity-row-actor">{{ $item->actorName }}</span>
            @endif
        </div>
    @endif
</div>
