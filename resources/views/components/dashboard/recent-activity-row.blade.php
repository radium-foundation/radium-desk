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
    $incidentLabel = $item->incidentLabel();
    $primaryName = $item->primaryName();
    $chips = $item->chips();
    $isClickable = $item->entityIncidentId !== null;
    $customer360Label = filled($item->customerName) ? $item->customerName : ($incidentLabel !== '' ? $incidentLabel : $primaryName);
@endphp

<div @class([
        'dashboard-activity-entry',
        'dashboard-activity-entry--indicator-'.$indicatorVariant,
        'dashboard-activity-entry--clickable' => $isClickable,
        'dashboard-activity-entry--history' => ! $showIncident,
    ])
     @if($isClickable)
         data-dashboard-activity-entry
         data-incident-id="{{ $item->entityIncidentId }}"
         data-customer-360-label="{{ $customer360Label }}"
         role="button"
         tabindex="0"
         aria-label="Open Customer 360 for {{ $customer360Label }}"
     @endif>
    <div class="dashboard-activity-entry-icon" aria-hidden="true">{{ $item->icon() }}</div>

    <div class="dashboard-activity-entry-main">
        <div class="dashboard-activity-entry-top">
            <span class="dashboard-activity-entry-name">{{ $primaryName }}</span>
            <time class="dashboard-activity-entry-time"
                  datetime="{{ $item->occurredAt->toIso8601String() }}"
                  title="{{ $item->exactTime }}">
                {{ $item->compactTime }}
            </time>
        </div>

        <div class="dashboard-activity-entry-action">{{ $item->title }}</div>

        @if($showIncident && $incidentLabel !== '')
            <div class="dashboard-activity-entry-ids">
                <span class="dashboard-activity-entry-incident-label">{{ $incidentLabel }}</span>
                @if($threadCount)
                    <span class="dashboard-activity-thread-count">({{ $threadCount }})</span>
                @endif
            </div>
        @endif

        @if($chips !== [])
            <div class="dashboard-activity-entry-chips">
                @foreach($chips as $chip)
                    <span class="dashboard-activity-pill">{{ $chip }}</span>
                @endforeach
            </div>
        @endif
    </div>
</div>
