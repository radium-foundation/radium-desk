@props([
    'item',
])

@php
    $indicatorVariant = match ($item->indicatorVariant) {
        'communication' => 'info',
        'automation' => 'automation',
        'remark' => 'muted',
        'error' => 'danger',
        default => $item->indicatorVariant,
    };
@endphp

<li class="dashboard-activity-item dashboard-activity-item--indicator-{{ $indicatorVariant }}"
    role="listitem">
    <span class="dashboard-activity-item-marker" aria-hidden="true"></span>

    <div class="dashboard-activity-item-main">
        <div class="dashboard-activity-item-heading">
            <span class="dashboard-activity-item-icon" aria-hidden="true">{{ $item->icon }}</span>
            <span class="dashboard-activity-item-title">{{ $item->title }}</span>
            @if($item->sourceBadge)
                <span class="dashboard-activity-item-source">{{ $item->sourceBadge }}</span>
            @endif
        </div>

        <div class="dashboard-activity-item-meta">
            @if($item->entityLabel && $item->entityIncidentId)
                <button type="button"
                        class="dashboard-activity-item-entity-link"
                        data-agent-open-customer-360="{{ $item->entityIncidentId }}"
                        data-agent-customer-name="{{ $item->entityReference }}">
                    {{ $item->entityLabel }}
                </button>
            @elseif($item->entityLabel)
                <span class="dashboard-activity-item-entity">{{ $item->entityLabel }}</span>
            @endif

            @if($item->entityLabel && ($item->relativeTime || $item->actorName))
                <span class="dashboard-activity-item-separator" aria-hidden="true">·</span>
            @endif

            <time class="dashboard-activity-item-time"
                  datetime="{{ $item->occurredAt->toIso8601String() }}"
                  title="{{ $item->exactTime }}">
                {{ $item->relativeTime }}
            </time>

            @if($item->actorName)
                <span class="dashboard-activity-item-separator" aria-hidden="true">·</span>
                <span class="dashboard-activity-item-actor">
                    @if($item->isAutomation)
                        <i class="bi {{ $item->actorIconClass }}" aria-hidden="true"></i>
                    @endif
                    {{ $item->actorName }}
                </span>
            @endif
        </div>

        @if($item->includes !== [])
            <div class="dashboard-activity-item-includes">
                <span class="dashboard-activity-item-includes-label">Includes:</span>
                @foreach($item->includes as $include)
                    <span>{{ $include }}</span>@if(! $loop->last)<span aria-hidden="true"> · </span>@endif
                @endforeach
            </div>
        @endif
    </div>
</li>
