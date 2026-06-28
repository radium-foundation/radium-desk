@props([
    'activityTimeline',
    'limit' => null,
    'compact' => false,
    'showHeading' => true,
    'heading' => 'Activity Timeline',
    'emptyMessage' => 'No activity recorded yet.',
])

@php
    $entries = $limit !== null
        ? $activityTimeline->take($limit)
        : $activityTimeline;

    $iconForTitle = function (string $title): string {
        return match (true) {
            str_contains(strtolower($title), 'service case') => 'bi-tools',
            str_contains(strtolower($title), 'transaction') => 'bi-credit-card',
            str_contains(strtolower($title), 'remark') => 'bi-chat-left-text',
            str_contains(strtolower($title), 'assigned') => 'bi-person-check',
            str_contains(strtolower($title), 'refund') => 'bi-arrow-counterclockwise',
            str_contains(strtolower($title), 'approval') => 'bi-shield-check',
            str_contains(strtolower($title), 'updated order') => 'bi-pencil-square',
            default => 'bi-circle-fill',
        };
    };
@endphp

<div @class([
    'order-workspace-timeline-wrap',
    'order-workspace-timeline-wrap--compact' => $compact,
])>
    @if($showHeading)
        <h3 class="order-workspace-section-title">{{ $heading }}</h3>
    @endif

    @if($entries->isEmpty())
        <div class="order-workspace-empty order-workspace-empty--inline">
            <i class="bi bi-clock-history" aria-hidden="true"></i>
            <p class="mb-0">{{ $emptyMessage }}</p>
        </div>
    @else
        <div class="order-workspace-timeline" role="list">
            @foreach($entries as $entry)
                <article class="order-workspace-timeline-item" role="listitem">
                    <div class="order-workspace-timeline-icon" aria-hidden="true">
                        <i class="bi {{ $iconForTitle($entry->title) }}"></i>
                    </div>
                    <div class="order-workspace-timeline-content">
                        <time class="order-workspace-timeline-time"
                              datetime="{{ $entry->occurredAt->toIso8601String() }}">
                            {{ display_app_timeline_datetime($entry->occurredAt) }}
                        </time>
                        <div class="order-workspace-timeline-action">{{ $entry->title }}</div>
                        @if($entry->actorName)
                            <div class="order-workspace-timeline-actor">{{ $entry->actorName }}</div>
                        @endif
                        @if($entry->correctionChanges !== [])
                            <div class="order-workspace-timeline-detail">
                                @foreach($entry->correctionChanges as $change)
                                    <div>{{ $change->label }}: {{ $change->previous }} → {{ $change->next }}</div>
                                @endforeach
                                @if($entry->correctionReason)
                                    <div class="mt-1">{{ $entry->correctionReason }}</div>
                                @endif
                            </div>
                        @elseif($entry->detail)
                            <div class="order-workspace-timeline-detail">{{ $entry->detail }}</div>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</div>
