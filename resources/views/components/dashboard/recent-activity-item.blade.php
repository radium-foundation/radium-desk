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
        <div class="dashboard-activity-item-content">
            <div class="dashboard-activity-item-heading">
                <span class="dashboard-activity-item-icon" aria-hidden="true">{{ $item->icon }}</span>
                <h3 class="dashboard-activity-item-title">{{ $item->title }}</h3>
                @if($item->sourceBadge)
                    <span class="dashboard-activity-item-source">{{ $item->sourceBadge }}</span>
                @endif
            </div>

            @if($item->entityLabel)
                <div class="dashboard-activity-item-entity">
                    @if($item->entityUrl)
                        <a href="{{ $item->entityUrl }}" class="dashboard-activity-item-entity-link">
                            {{ $item->entityLabel }}
                        </a>
                    @else
                        <span>{{ $item->entityLabel }}</span>
                    @endif
                </div>
            @endif

            @if($item->includes !== [])
                <div class="dashboard-activity-item-includes">
                    <span class="dashboard-activity-item-includes-label">Includes:</span>
                    <ul class="dashboard-activity-item-includes-list">
                        @foreach($item->includes as $include)
                            <li>{{ $include }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <time class="dashboard-activity-item-time"
                  datetime="{{ $item->occurredAt->toIso8601String() }}"
                  title="{{ $item->exactTime }}">
                {{ $item->relativeTime }}
            </time>
        </div>

        <div class="dashboard-activity-item-actor">
            @if($item->actorUser)
                <x-dashboard-user-avatar :user="$item->actorUser" aria-prefix="By" />
                <span class="dashboard-activity-item-actor-name">{{ $item->actorName }}</span>
            @else
                <span class="dashboard-activity-item-actor-automation" title="{{ $item->actorName }}">
                    <i class="bi {{ $item->actorIconClass }}" aria-hidden="true"></i>
                    <span>{{ $item->actorName }}</span>
                </span>
            @endif
        </div>
    </div>
</li>
