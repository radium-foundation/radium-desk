@props([
    'item',
    'showIncident' => true,
])

@php
    $isClickable = $item->entityIncidentId !== null;
    $statusMark = $item->statusMark();
    $customer360Label = $isClickable ? $item->customer360Label() : null;
    $incidentLabel = $showIncident ? $item->incidentLabel() : '';
@endphp

<div @class([
        'dashboard-activity-entry',
        'dashboard-activity-entry--clickable' => $isClickable,
        'dashboard-activity-entry--history' => ! $showIncident,
    ])
     @if($statusMark) data-status="{{ $statusMark }}" @endif
     @if($isClickable)
         data-dashboard-activity-entry
         data-incident-id="{{ $item->entityIncidentId }}"
         data-customer-360-label="{{ $customer360Label }}"
         role="button"
         tabindex="0"
         aria-label="Open Customer 360 for {{ $customer360Label }}"
     @endif>
    <svg class="dashboard-activity-entry-icon" width="12" height="12" aria-hidden="true" focusable="false">
        <use href="#da-{{ $item->iconKey() }}"></use>
    </svg>

    <span class="dashboard-activity-entry-name" title="{{ $item->primaryName() }}">{{ $item->primaryName() }}</span>

    <span class="dashboard-activity-entry-action" title="{{ $item->title }}">{{ $item->actionLabel() }}</span>

    <span class="dashboard-activity-entry-incident-label" @if($incidentLabel !== '') title="{{ $incidentLabel }}" @endif>{{ $incidentLabel }}</span>

    <span class="dashboard-activity-channel">{{ $item->channelBadge() }}</span>

    <time class="dashboard-activity-entry-time"
          datetime="{{ $item->occurredAt->toIso8601String() }}"
          title="{{ $item->exactTime }}">{{ $item->compactTime }}</time>
</div>
