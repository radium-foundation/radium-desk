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
    $customerLabel = filled($item->customerName) ? $item->customerName : null;
    $chips = $item->chips();
    $isClickable = $item->entityIncidentId !== null;
    $customer360Label = $customerLabel ?? $incidentLabel;
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
    @if($showIncident && $incidentLabel !== '')
        <div class="dashboard-activity-entry-incident">
            <span class="dashboard-activity-entry-incident-label">{{ $incidentLabel }}</span>
            @if($threadCount)
                <span class="dashboard-activity-thread-count">({{ $threadCount }})</span>
            @endif
        </div>
    @endif

    <time class="dashboard-activity-entry-time"
          datetime="{{ $item->occurredAt->toIso8601String() }}"
          title="{{ $item->exactTime }}">
        {{ $item->compactTime }}
    </time>

    <div class="dashboard-activity-entry-summary">
        @if($customerLabel)
            <span class="dashboard-activity-entry-customer">{{ $customerLabel }}</span>
            <span class="dashboard-activity-entry-separator" aria-hidden="true">•</span>
        @endif
        <span class="dashboard-activity-entry-action">{{ $item->title }}</span>
    </div>

    @if($chips !== [])
        <div class="dashboard-activity-entry-chips">
            @foreach($chips as $chip)
                <span @class([
                    'dashboard-activity-pill',
                    'dashboard-activity-pill--ira' => $chip === 'IRA',
                ])>{{ $chip === 'IRA' ? '🤖 IRA' : $chip }}</span>
            @endforeach
        </div>
    @endif
</div>
